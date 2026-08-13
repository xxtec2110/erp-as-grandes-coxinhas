<?php

namespace App\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class MetaWhatsAppMediaDownloader implements WhatsAppMediaDownloaderInterface
{
    public function download(string $mediaId): DownloadedMedia
    {
        $this->assertConfigured();
        $metadata = $this->request(fn () => Http::withToken((string) config('whatsapp.access_token'))->acceptJson()->timeout((int) config('whatsapp.media_timeout', 20))->get($this->mediaPath($mediaId)));
        $url = $metadata->json('url');
        $mime = $metadata->json('mime_type');
        if (! is_string($url) || ! is_string($mime) || ! $this->allowedUrl($url)) {
            throw new MediaDownloadException('media_metadata_invalid');
        }
        $content = $this->request(fn () => Http::withToken((string) config('whatsapp.access_token'))->timeout((int) config('whatsapp.media_timeout', 20))->get($url));
        $body = $content->body();
        if ($body === '') {
            throw new MediaDownloadException('media_empty');
        }

        return new DownloadedMedia($mediaId, $mime, $mediaId.'.'.$this->extension($mime), $body);
    }

    private function request(callable $callback): Response
    {
        $attempts = max(1, (int) config('whatsapp.media_max_attempts', 2));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $callback();
            } catch (ConnectionException $exception) {
                if ($attempt === $attempts) {
                    throw new TransientMediaDownloadException('media_timeout', previous: $exception);
                }

                continue;
            }
            if (($response->status() === 429 || $response->serverError()) && $attempt < $attempts) {
                continue;
            }
            if ($response->status() === 404 || $response->status() === 410) {
                throw new MediaDownloadException('media_expired_or_not_found');
            }
            if ($response->status() === 429 || $response->serverError()) {
                throw new TransientMediaDownloadException('media_provider_temporary_failure');
            }
            if (! $response->successful()) {
                throw new MediaDownloadException('media_download_rejected');
            }

            return $response;
        }

        throw new TransientMediaDownloadException('media_provider_unavailable');
    }

    private function assertConfigured(): void
    {
        if (! config('whatsapp.media_download_enabled') || blank(config('whatsapp.access_token'))) {
            throw new MediaDownloadException('media_downloader_not_configured');
        }
    }

    private function mediaPath(string $mediaId): string
    {
        return rtrim((string) config('whatsapp.graph_base_url'), '/').'/'.trim((string) config('whatsapp.api_version'), '/').'/'.rawurlencode($mediaId);
    }

    private function allowedUrl(string $url): bool
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return false;
        }
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        return collect(config('whatsapp.media_allowed_hosts', []))->contains(fn (string $allowed) => $host === $allowed || str_ends_with($host, '.'.$allowed));
    }

    private function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/amr' => 'amr',
            default => throw new MediaDownloadException('media_type_not_allowed'),
        };
    }
}
