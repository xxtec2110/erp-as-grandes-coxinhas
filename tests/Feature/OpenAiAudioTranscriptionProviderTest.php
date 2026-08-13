<?php

namespace Tests\Feature;

use App\Agent\AudioTranscriptionException;
use App\Agent\OpenAiAudioTranscriptionProvider;
use App\Models\AgentAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OpenAiAudioTranscriptionProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set(['ai.openai.enabled' => true, 'ai.openai.api_key' => 'test-openai-key', 'ai.openai.base_url' => 'https://openai.invalid/v1', 'ai.openai.max_attempts' => 2, 'ai.models.audio' => 'audio-test']);
        Http::preventStrayRequests();
    }

    public function test_transcribes_private_audio_with_configured_model(): void
    {
        Http::fake(['openai.invalid/*' => Http::response(['text' => 'ESTOQUE CATANDUVA', 'duration' => 4.2, 'usage' => ['input_tokens' => 8, 'output_tokens' => 3]], 200)]);
        $result = app(OpenAiAudioTranscriptionProvider::class)->transcribe($this->attachment());

        $this->assertSame('ESTOQUE CATANDUVA', $result->text);
        $this->assertSame('4.2', $result->duration);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://openai.invalid/v1/audio/transcriptions' && $request->hasHeader('Authorization', 'Bearer test-openai-key'));
    }

    public function test_missing_configuration_fails_closed_without_http(): void
    {
        config()->set('ai.openai.api_key', null);
        $this->expectException(AudioTranscriptionException::class);
        try {
            app(OpenAiAudioTranscriptionProvider::class)->transcribe($this->attachment());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_retries_429(): void
    {
        Http::fakeSequence()->push([], 429)->push(['text' => 'MENU'], 200);
        $this->assertSame('MENU', app(OpenAiAudioTranscriptionProvider::class)->transcribe($this->attachment())->text);

    }

    public function test_rejects_invalid_provider_shape(): void
    {
        Http::fake(['*' => Http::response(['unexpected' => true], 200)]);
        $this->expectException(AudioTranscriptionException::class);
        app(OpenAiAudioTranscriptionProvider::class)->transcribe($this->attachment('second.ogg'));
    }

    public function test_timeout_and_500_fail_without_external_fallback(): void
    {
        Http::fake(['*' => Http::failedConnection('timeout')]);
        try {
            app(OpenAiAudioTranscriptionProvider::class)->transcribe($this->attachment());
            $this->fail('Timeout deveria falhar.');
        } catch (AudioTranscriptionException) {
            $this->assertTrue(true);
        }
        Http::fake(['*' => Http::response([], 500)]);
        $this->expectException(AudioTranscriptionException::class);
        app(OpenAiAudioTranscriptionProvider::class)->transcribe($this->attachment('server.ogg'));
    }

    private function attachment(string $name = 'audio.ogg'): AgentAttachment
    {
        $path = 'agent-attachments/test/'.$name;
        Storage::disk('local')->put($path, 'OggSaudio');

        return AgentAttachment::query()->create(['source' => 'test', 'content_hash' => hash('sha256', $name), 'disk' => 'local', 'path' => $path, 'original_name' => $name, 'mime_type' => 'audio/ogg', 'size' => 9, 'processing_status' => 'stored']);
    }
}
