<?php

namespace App\Services;

use App\Models\AgentAttachment;
use App\Models\User;
use App\WhatsApp\DownloadedMedia;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AgentAttachmentService
{
    public function __construct(private AuthorizationService $authorization) {}

    public function store(UploadedFile $file, string $purpose, int $locationId, string $retentionType, User $user): AgentAttachment
    {
        $realPath = $file->getRealPath();
        if (! is_string($realPath) || ! is_file($realPath)) {
            throw new DomainException('O arquivo temporário não está disponível.');
        }
        $mime = $this->detectMime($realPath);
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $allowed = config('attachments.allowed_mimes', []);
        if (! isset($allowed[$mime]) || ! in_array($extension, $allowed[$mime], true)) {
            throw new DomainException('O conteúdo do arquivo não corresponde a um formato permitido.');
        }
        $size = $file->getSize();
        if (! is_int($size) || $size < 1) {
            throw new DomainException('O arquivo está vazio ou não pôde ser lido.');
        }
        $limitMb = match (true) {
            str_starts_with($mime, 'image/') => (int) config('attachments.max_image_mb'),
            str_starts_with($mime, 'audio/') => (int) config('attachments.max_audio_mb'),
            default => (int) config('attachments.max_document_mb'),
        };
        if ($size > $limitMb * 1024 * 1024) {
            throw new DomainException("O arquivo excede o limite de {$limitMb} MB.");
        }
        $this->authorize($user, $purpose, $mime, $locationId, true);
        $hash = hash_file('sha256', $realPath);
        $existing = AgentAttachment::query()->where('content_hash', $hash)->first();
        if ($existing !== null) {
            if ($existing->created_by !== $user->id || $existing->location_id !== $locationId) {
                throw new DomainException('Este conteúdo já existe em outro contexto protegido.');
            }

            return $existing;
        }
        $disk = (string) config('attachments.disk', 'local');
        $generatedName = (string) Str::uuid().'.'.$extension;
        $path = 'agent-attachments/'.substr($hash, 0, 2).'/'.$generatedName;
        $originalName = $this->sanitizeOriginalName($file->getClientOriginalName());
        $attachment = AgentAttachment::query()->create([
            'source' => 'web_'.$purpose,
            'content_hash' => $hash,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $originalName,
            'mime_type' => $mime,
            'size' => $size,
            'processing_status' => 'stored',
            'retention_type' => $retentionType,
            'created_by' => $user->id,
            'location_id' => $locationId,
            'metadata' => ['purpose' => $purpose],
        ]);
        if (! Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path))) {
            $attachment->delete();
            throw new DomainException('Não foi possível armazenar o arquivo com segurança.');
        }

        return $attachment->refresh();
    }

    public function storeDownloaded(DownloadedMedia $media, string $purpose, int $locationId, string $retentionType, User $user, ?int $inboundMessageId = null): AgentAttachment
    {
        $existing = AgentAttachment::query()->where('source', 'whatsapp')->where('external_id', $media->mediaId)->where('created_by', $user->id)->whereNotNull('path')->first();
        if ($existing !== null) {
            return $existing;
        }
        $temporary = tempnam(sys_get_temp_dir(), 'agent-media-');
        if (! is_string($temporary) || file_put_contents($temporary, $media->contents) === false) {
            throw new DomainException('Não foi possível preparar a mídia temporária.');
        }
        try {
            $file = new UploadedFile($temporary, $media->filename, $media->mimeType, null, true);
            $attachment = $this->store($file, $purpose, $locationId, $retentionType, $user);
            $metadata = $attachment->metadata ?? [];
            $metadata['provider_media_id'] = $media->mediaId;
            $attachment->update(['source' => 'whatsapp', 'external_id' => $media->mediaId, 'whatsapp_inbound_message_id' => $inboundMessageId, 'metadata' => $metadata]);

            return $attachment->refresh();
        } finally {
            @unlink($temporary);
        }
    }

    public function authorizeDownload(AgentAttachment $attachment, User $user): void
    {
        $purpose = (string) ($attachment->metadata['purpose'] ?? str_replace('web_', '', $attachment->source));
        $this->authorize($user, $purpose, (string) $attachment->mime_type, $attachment->location_id, false);
        if ($attachment->disk === null || $attachment->path === null || ! Storage::disk($attachment->disk)->exists($attachment->path)) {
            throw new DomainException('Arquivo protegido não encontrado.');
        }
    }

    public function authorizeLink(int $attachmentId, string $purpose, int $locationId, User $user): AgentAttachment
    {
        $attachment = AgentAttachment::query()->findOrFail($attachmentId);
        if (! $user->is_super_admin && $attachment->location_id !== $locationId) {
            throw new DomainException('O anexo não pertence à unidade informada.');
        }
        $storedPurpose = $attachment->metadata['purpose'] ?? null;
        if ($storedPurpose !== null && $storedPurpose !== $purpose) {
            throw new DomainException('O anexo não pertence a este fluxo operacional.');
        }
        $this->authorize($user, $purpose, (string) $attachment->mime_type, $locationId, true);

        return $attachment;
    }

    private function authorize(User $user, string $purpose, string $mime, ?int $locationId, bool $write): void
    {
        $permission = match ($purpose) {
            'purchase' => $write ? 'purchases.create' : 'purchases.view',
            'finance' => $write ? 'finance.payments.create' : 'finance.view',
            default => match (true) {
                str_starts_with($mime, 'image/') => 'agent.image.use',
                str_starts_with($mime, 'audio/') => 'agent.audio.use',
                default => 'agent.document.use',
            },
        };
        $this->authorization->authorize($user, $permission, $locationId);
    }

    private function sanitizeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?? 'arquivo';
        $name = trim($name, ". \t\n\r\0\x0B");

        return Str::limit($name !== '' ? $name : 'arquivo', 200, '');
    }

    private function detectMime(string $path): string
    {
        $header = file_get_contents($path, false, null, 0, 8);
        if (! is_string($header)) {
            throw new DomainException('Não foi possível verificar o conteúdo do arquivo.');
        }

        return match (true) {
            str_starts_with($header, '%PDF-') => 'application/pdf',
            str_starts_with($header, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($header, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($header, 'OggS') => 'audio/ogg',
            str_starts_with($header, 'ID3'), str_starts_with($header, "\xFF\xFB"), str_starts_with($header, "\xFF\xF3"), str_starts_with($header, "\xFF\xF2") => 'audio/mpeg',
            substr($header, 4, 4) === 'ftyp' => 'audio/mp4',
            str_starts_with($header, "#!AMR\n") => 'audio/amr',
            default => throw new DomainException('A assinatura do arquivo não corresponde a PDF, JPEG ou PNG.'),
        };
    }
}
