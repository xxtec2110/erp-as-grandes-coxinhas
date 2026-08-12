<?php

namespace App\Providers;

use App\Agent\AiProviderInterface;
use App\Agent\FakeAiProvider;
use App\Agent\UnavailableAiProvider;
use App\WhatsApp\DisabledWhatsAppClient;
use App\WhatsApp\FakeWhatsAppClient;
use App\WhatsApp\MetaWhatsAppClient;
use App\WhatsApp\WhatsAppClientInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiProviderInterface::class, function () {
            $provider = (string) config('agent_costs.provider', 'disabled');
            if ($provider === 'fake' && app()->environment(['local', 'testing'])) {
                return new FakeAiProvider;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
