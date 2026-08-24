<?php

namespace App\Agent;

use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

class OpenAiProvider implements AiProviderInterface
{
    public function __construct(private AgentSystemPrompt $prompt) {}

    public function interpret(AgentMessage $message, array $availableTools, array $context = []): ?AiInterpretation
    {
        $apiKey = (string) config('ai.openai.api_key');
        $model = $this->model($message->messageType);
        if ($apiKey === '' || $model === '' || ! config('ai.openai.enabled')) {
            throw new AiProviderUnavailableException('ai_provider_not_configured');
        }
        $content = [];
        if (filled($message->text)) {
            $content[] = ['type' => 'input_text', 'text' => $message->text];
        }
        if (($conversation = $context['conversation'] ?? []) !== []) {
            array_unshift($content, ['type' => 'input_text', 'text' => 'Contexto confiável da conversa, validado pelo ERP: '.json_encode($conversation, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        }
        foreach ($context['attachments'] ?? [] as $attachment) {
            $content[] = str_starts_with($attachment['mime_type'], 'image/')
                ? ['type' => 'input_image', 'image_url' => 'data:'.$attachment['mime_type'].';base64,'.$attachment['data']]
                : ['type' => 'input_file', 'filename' => $attachment['filename'], 'file_data' => 'data:'.$attachment['mime_type'].';base64,'.$attachment['data']];
        }
        if ($content === []) {
            return null;
        }
        $tools = array_values(array_filter(array_map(fn (string $name) => app(AgentToolRegistry::class)->get($name), $availableTools)));
        $payload = ['model' => $model, 'instructions' => $this->prompt->build($tools), 'input' => [['role' => 'user', 'content' => $content]], 'text' => ['format' => ['type' => 'json_schema', 'name' => 'erp_agent_interpretation', 'strict' => false, 'schema' => AgentInterpretationSchema::definition()]]];

        $attempts = max(1, (int) config('ai.openai.max_attempts', 2));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::withToken($apiKey)->acceptJson()->timeout((int) config('ai.openai.timeout', 30))->post(rtrim((string) config('ai.openai.base_url'), '/').'/responses', $payload);
            } catch (ConnectionException $exception) {
                if ($attempt === $attempts) {
                    throw new AiProviderUnavailableException('ai_provider_timeout', previous: $exception);
                }

                continue;
            }
            if (($response->status() === 429 || $response->serverError()) && $attempt < $attempts) {
                continue;
            }
            if (! $response->successful()) {
                throw new AiProviderUnavailableException('ai_provider_http_'.$response->status());
            }
            $text = collect($response->json('output', []))->flatMap(fn (array $item) => $item['content'] ?? [])->first(fn (array $item) => isset($item['text']))['text'] ?? null;
            if (! is_string($text)) {
                throw new AiProviderResponseException('ai_provider_invalid_response');
            }
            try {
                $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new AiProviderResponseException('ai_provider_invalid_json', previous: $exception);
            }

            try {
                return AiInterpretation::fromArray($decoded, [
                    'model' => $model,
                    'input_tokens' => data_get($response->json(), 'usage.input_tokens'),
                    'cached_input_tokens' => data_get($response->json(), 'usage.input_tokens_details.cached_tokens'),
                    'output_tokens' => data_get($response->json(), 'usage.output_tokens'),
                ]);
            } catch (DomainException $exception) {
                throw new AiProviderResponseException('ai_provider_invalid_schema', previous: $exception);
            }
        }

        throw new AiProviderUnavailableException('ai_provider_unavailable');
    }

    private function model(string $type): string
    {
        return (string) match ($type) {
            'image' => config('ai.models.vision'), 'document' => config('ai.models.document'), default => config('ai.models.text'),
        };
    }
}
