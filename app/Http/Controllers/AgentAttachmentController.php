<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAgentAttachmentRequest;
use App\Models\AgentAttachment;
use App\Services\AgentAttachmentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentAttachmentController extends Controller
{
    public function store(StoreAgentAttachmentRequest $request, AgentAttachmentService $attachments): JsonResponse|RedirectResponse
    {
        try {
            $attachment = $attachments->store($request->file('attachment'), $request->string('purpose')->toString(), $request->integer('location_id'), $request->string('retention_type')->toString(), $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['attachment' => $exception->getMessage()]);
        }
        if ($request->expectsJson()) {
            return response()->json(['id' => $attachment->id, 'status' => $attachment->processing_status], 201);
        }

        return back()->with('success', 'Arquivo armazenado com segurança.');
    }

    public function download(AgentAttachment $attachment, AgentAttachmentService $attachments): StreamedResponse
    {
        $attachments->authorizeDownload($attachment, request()->user());

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }
}
