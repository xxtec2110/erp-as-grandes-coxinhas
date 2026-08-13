<?php

namespace Tests\Feature;

use App\WhatsApp\MediaDownloadException;
use App\WhatsApp\MetaWhatsAppMediaDownloader;
use App\WhatsApp\TransientMediaDownloadException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaWhatsAppMediaDownloaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set(['whatsapp.media_download_enabled' => true, 'whatsapp.access_token' => 'test-meta-token', 'whatsapp.graph_base_url' => 'https://graph.facebook.com', 'whatsapp.api_version' => 'v23.0', 'whatsapp.media_max_attempts' => 2, 'whatsapp.media_timeout' => 1]);
        Http::preventStrayRequests();
    }

    public function test_downloads_allowed_image_pdf_and_audio_using_metadata_first(): void
    {
        foreach ([['image/jpeg', 'image.jpg', "\xFF\xD8\xFFdata"], ['application/pdf', 'document.pdf', '%PDF-data'], ['audio/ogg', 'audio.ogg', 'OggSdata']] as $index => [$mime, $filename, $body]) {
            $id = 'media-'.$index;
            $url = 'https://lookaside.fbsbx.com/whatsapp_business/attachments/'.$id;
            Http::fake([
                "graph.facebook.com/v23.0/{$id}" => Http::response(['id' => $id, 'mime_type' => $mime, 'url' => $url], 200),
                $url => Http::response($body, 200),
            ]);
            $result = app(MetaWhatsAppMediaDownloader::class)->download($id);
            $this->assertSame($mime, $result->mimeType);
            $this->assertSame($body, $result->contents);
        }
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-meta-token'));
    }

    public function test_retries_429_and_500_then_succeeds(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return Http::response([], 429);
            }
            if ($calls === 2) {
                return Http::response(['mime_type' => 'application/pdf', 'url' => 'https://lookaside.fbsbx.com/file'], 200);
            }
            if ($calls === 3) {
                return Http::response([], 500);
            }

            return Http::response('%PDF-ok', 200);
        });

        $this->assertSame('%PDF-ok', app(MetaWhatsAppMediaDownloader::class)->download('retry')->contents);
        $this->assertSame(4, $calls);
    }

    public function test_timeout_and_final_server_error_are_transient(): void
    {
        Http::fake(['*' => Http::failedConnection('timeout')]);
        try {
            app(MetaWhatsAppMediaDownloader::class)->download('timeout');
            $this->fail('Timeout deveria falhar.');
        } catch (TransientMediaDownloadException) {
            $this->assertTrue(true);
        }

        Http::fake(['*' => Http::response([], 503)]);
        $this->expectException(TransientMediaDownloadException::class);
        app(MetaWhatsAppMediaDownloader::class)->download('server-error');
    }

    public function test_missing_expired_invalid_metadata_and_untrusted_url_are_permanent(): void
    {
        foreach ([
            Http::response([], 404),
            Http::response(['mime_type' => 'image/jpeg'], 200),
            Http::response(['mime_type' => 'image/jpeg', 'url' => 'http://lookaside.fbsbx.com/file'], 200),
            Http::response(['mime_type' => 'image/jpeg', 'url' => 'https://evil.example/file'], 200),
        ] as $response) {
            Http::fake(['*' => $response]);
            try {
                app(MetaWhatsAppMediaDownloader::class)->download('invalid');
                $this->fail('Mídia inválida deveria ser rejeitada.');
            } catch (MediaDownloadException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_disabled_or_missing_token_never_sends_http(): void
    {
        config()->set('whatsapp.media_download_enabled', false);
        $this->expectException(MediaDownloadException::class);
        try {
            app(MetaWhatsAppMediaDownloader::class)->download('disabled');
        } finally {
            Http::assertNothingSent();
        }
    }
}
