<?php

namespace App\Providers;

use App\Agent\AiProviderInterface;
use App\Agent\AudioTranscriptionProviderInterface;
use App\Agent\FakeAiProvider;
use App\Agent\FakeAudioTranscriptionProvider;
use App\Agent\OpenAiAudioTranscriptionProvider;
use App\Agent\OpenAiProvider;
use App\Agent\UnavailableAiProvider;
use App\Agent\UnavailableAudioTranscriptionProvider;
use App\Pdv\FakePdvProvider;
use App\WhatsApp\DisabledWhatsAppClient;
use App\WhatsApp\FakeWhatsAppClient;
use App\WhatsApp\FakeWhatsAppMediaDownloader;
use App\WhatsApp\MetaWhatsAppClient;
use App\WhatsApp\MetaWhatsAppMediaDownloader;
use App\WhatsApp\UnavailableWhatsAppMediaDownloader;
use App\WhatsApp\WhatsAppClientInterface;
use App\WhatsApp\WhatsAppMediaDownloaderInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FakePdvProvider::class);
        $this->app->singleton(AiProviderInterface::class, function () {
            $provider = (string) config('ai.provider', 'disabled');
            if ($provider === 'fake' && app()->environment(['local', 'testing'])) {
                return new FakeAiProvider;
            }
            if ($provider === 'openai' && config('ai.openai.enabled') && filled(config('ai.openai.api_key'))) {
                return app(OpenAiProvider::class);
            }

            return new UnavailableAiProvider;
        });
        $this->app->singleton(WhatsAppClientInterface::class, function () {
            if (config('whatsapp.client') === 'fake') {
                return new FakeWhatsAppClient;
            }
            if (config('whatsapp.provider') === 'meta' && config('whatsapp.enabled')) {
                return new MetaWhatsAppClient;
            }

            return new DisabledWhatsAppClient;
        });
        $this->app->singleton(WhatsAppMediaDownloaderInterface::class, function () {
            return match ((string) config('whatsapp.media_downloader')) {
                'fake' => app()->environment(['local', 'testing']) ? new FakeWhatsAppMediaDownloader : new UnavailableWhatsAppMediaDownloader,
                'meta' => config('whatsapp.media_download_enabled') ? new MetaWhatsAppMediaDownloader : new UnavailableWhatsAppMediaDownloader,
                default => new UnavailableWhatsAppMediaDownloader,
            };
        });
        $this->app->singleton(AudioTranscriptionProviderInterface::class, function () {
            return match ((string) config('ai.audio_provider')) {
                'fake' => app()->environment(['local', 'testing']) ? new FakeAudioTranscriptionProvider : new UnavailableAudioTranscriptionProvider,
                'openai' => config('ai.openai.enabled') && filled(config('ai.openai.api_key')) ? new OpenAiAudioTranscriptionProvider : new UnavailableAudioTranscriptionProvider,
                default => new UnavailableAudioTranscriptionProvider,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
