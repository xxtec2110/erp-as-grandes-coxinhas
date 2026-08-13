<?php

namespace App\Agent;

use App\Models\AgentAttachment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class OpenAiAudioTranscriptionProvider implements AudioTranscriptionProviderInterface
{
    public function transcribe(AgentAttachment $attachment): AudioTranscription
    {
        $key = (string) config('ai.openai.api_key');
        $model = (string) config('ai.models.audio');
        if (! config('ai.openai.enabled') || $key === '' || $model === '') {
            throw new AudioTranscriptionException('audio_transcription_not_configured');
        }
        $contents = Storage::disk($attachment->disk)->get($attachment->path);
        $attempts = max(1, (int) config('ai.openai.max_attempts', 2));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::withToken($key)->acceptJson()->timeout((int) config('ai.openai.timeout', 30))
                    ->attach('file', $contents, $attachment->original_name ?? 'audio.ogg')
                    ->post(rtrim((string) config('ai.openai.base_url'), '/').'/audio/transcriptions', ['model' => $model, 'response_format' => 'verbose_json']);
            } catch (ConnectionException $exception) {
                if ($attempt === $attempts) {
                    throw new AudioTranscriptionException('audio_transcription_timeout', previous: $exception);
                }

                continue;
            }
            if (($response->status() === 429 || $response->serverError()) && $attempt < $attempts) {
                continue;
            }
            if (! $response->successful()) {
                throw new AudioTranscriptionException('audio_transcription_http_'.$response->status());
            }
            $text = $response->json('text');
            if (! is_string($text)) {
                throw new AudioTranscriptionException('audio_transcription_invalid_response');
            }

            return new AudioTranscription(trim($text), isset($response['duration']) ? (string) $response['duration'] : null, ['model' => $model, 'input_tokens' => $response->json('usage.input_tokens'), 'output_tokens' => $response->json('usage.output_tokens')]);
        }

        throw new AudioTranscriptionException('audio_transcription_unavailable');
    }
}
