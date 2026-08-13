<?php

namespace Tests\Feature;

use App\Agent\AiInterpretation;
use App\Agent\AiProviderInterface;
use App\Agent\AudioTranscription;
use App\Agent\AudioTranscriptionProviderInterface;
use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\AgentCostService;
use App\Services\StockMovementService;
use App\WhatsApp\DownloadedMedia;
use App\WhatsApp\FakeWhatsAppClient;
use App\WhatsApp\WhatsAppChannelAdapter;
use App\WhatsApp\WhatsAppClientInterface;
use App\WhatsApp\WhatsAppMediaDownloaderInterface;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class WhatsAppAudioPipelineTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsAppClient $client;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        Storage::fake('local');
        config()->set(['whatsapp.client' => 'fake', 'whatsapp.media_downloader' => 'fake', 'ai.audio_provider' => 'fake']);
        $this->client = new FakeWhatsAppClient;
        $this->app->instance(WhatsAppClientInterface::class, $this->client);
        $this->location = Location::query()->create(['name' => 'Catanduva', 'type' => 'store', 'active' => true]);
    }

    public function test_authorized_audio_is_downloaded_transcribed_once_and_uses_deterministic_parser(): void
    {
        $this->known('551100001001', true, ['agent.audio.use', 'stock.view']);
        $product = Product::query()->create(['name' => 'Frango', 'stock_unit' => 'un', 'active' => true]);
        app(StockMovementService::class)->record(new RecordStockMovementData($product->id, $this->location->id, StockMovementType::OpeningBalance, '20', now()->toDateString(), 'audio-stock'));
        $downloader = Mockery::mock(WhatsAppMediaDownloaderInterface::class);
        $downloader->shouldReceive('download')->once()->with('audio-safe')->andReturn(new DownloadedMedia('audio-safe', 'audio/ogg', 'audio.ogg', 'OggSaudio-test'));
        $transcriber = Mockery::mock(AudioTranscriptionProviderInterface::class);
        $transcriber->shouldReceive('transcribe')->once()->andReturn(new AudioTranscription('ESTOQUE CATANDUVA', '3.5', ['model' => 'audio-fake']));
        $this->app->instance(WhatsAppMediaDownloaderInterface::class, $downloader);
        $this->app->instance(AudioTranscriptionProviderInterface::class, $transcriber);

        app(WhatsAppChannelAdapter::class)->handle($this->payload('wamid.audio.1', '551100001001', 'audio-safe'));
        app(WhatsAppChannelAdapter::class)->handle($this->payload('wamid.audio.2', '551100001001', 'audio-safe'));

        $this->assertCount(2, $this->client->sent());
        $this->assertStringContainsString('Frango: 20', $this->client->sent()[0]['text']);
        $this->assertDatabaseCount('agent_attachments', 1);
        $this->assertDatabaseCount('agent_usage_costs', 5);
        $this->assertDatabaseHas('agent_usage_costs', ['usage_type' => 'ai_audio', 'provider' => 'fake']);
        $this->assertDatabaseHas('agent_events', ['event_type' => 'audio_transcription_cache_hit']);
        $this->assertDatabaseMissing('agent_events', ['external_message_id' => 'wamid.audio.1', 'event_type' => 'ai_called']);
    }

    public function test_missing_permission_or_voice_flag_blocks_before_download(): void
    {
        $this->known('551100001002', true, ['stock.view']);
        $this->known('551100001003', false, ['agent.audio.use', 'stock.view']);
        $downloader = Mockery::mock(WhatsAppMediaDownloaderInterface::class);
        $downloader->shouldNotReceive('download');
        $transcriber = Mockery::mock(AudioTranscriptionProviderInterface::class);
        $transcriber->shouldNotReceive('transcribe');
        $this->app->instance(WhatsAppMediaDownloaderInterface::class, $downloader);
        $this->app->instance(AudioTranscriptionProviderInterface::class, $transcriber);

        app(WhatsAppChannelAdapter::class)->handle($this->payload('wamid.denied.permission', '551100001002', 'denied-1'));
        app(WhatsAppChannelAdapter::class)->handle($this->payload('wamid.denied.flag', '551100001003', 'denied-2'));

        $this->assertDatabaseCount('agent_attachments', 0);
        $this->assertStringContainsString('não está liberado', $this->client->sent()[0]['text']);
        $this->assertStringContainsString('não está liberado', $this->client->sent()[1]['text']);
    }

    public function test_empty_transcription_never_executes_and_asks_for_repetition(): void
    {
        $this->known('551100001004', true, ['agent.audio.use', 'stock.view']);
        $downloader = Mockery::mock(WhatsAppMediaDownloaderInterface::class);
        $downloader->shouldReceive('download')->once()->andReturn(new DownloadedMedia('empty', 'audio/ogg', 'audio.ogg', 'OggSempty'));
        $transcriber = Mockery::mock(AudioTranscriptionProviderInterface::class);
        $transcriber->shouldReceive('transcribe')->once()->andReturn(new AudioTranscription(''));
        $this->app->instance(WhatsAppMediaDownloaderInterface::class, $downloader);
        $this->app->instance(AudioTranscriptionProviderInterface::class, $transcriber);

        app(WhatsAppChannelAdapter::class)->handle($this->payload('wamid.empty', '551100001004', 'empty'));

        $this->assertStringContainsString('Não consegui entender esse áudio', $this->client->sent()[0]['text']);
        $this->assertDatabaseMissing('agent_events', ['external_message_id' => 'wamid.empty', 'event_type' => 'tool_executed']);
    }

    public function test_non_deterministic_transcription_uses_text_ai_only_after_transcription(): void
    {
        $user = $this->known('551100001005', true, ['agent.audio.use', 'agent.free_chat.use', 'stock.view']);
        UserExternalIdentity::query()->where('user_id', $user->id)->update(['free_chat_allowed' => true]);
        $downloader = Mockery::mock(WhatsAppMediaDownloaderInterface::class);
        $downloader->shouldReceive('download')->once()->andReturn(new DownloadedMedia('free-audio', 'audio/ogg', 'audio.ogg', 'OggSfree'));
        $transcriber = Mockery::mock(AudioTranscriptionProviderInterface::class);
        $transcriber->shouldReceive('transcribe')->once()->andReturn(new AudioTranscription('quanto existe agora?', '2'));
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('interpret')->once()->andReturn(AiInterpretation::fromArray(['intent' => 'stock', 'tool' => 'stock.positions.list', 'confidence' => 0.9, 'fields' => ['location_id' => $this->location->id], 'missing_fields' => [], 'source_type' => 'text', 'document_type' => 'none', 'summary' => 'Consulta.']));
        $this->app->instance(WhatsAppMediaDownloaderInterface::class, $downloader);
        $this->app->instance(AudioTranscriptionProviderInterface::class, $transcriber);
        $this->app->instance(AiProviderInterface::class, $provider);

        app(WhatsAppChannelAdapter::class)->handle($this->payload('wamid.free.audio', '551100001005', 'free-audio'));

        $this->assertDatabaseHas('agent_events', ['external_message_id' => 'wamid.free.audio', 'event_type' => 'ai_called']);
    }

    public function test_saving_mode_blocks_audio_before_download_without_breaking_channel(): void
    {
        $this->known('551100001006', true, ['agent.audio.use', 'stock.view']);
        app(AgentCostService::class)->settings()->update(['saving_threshold' => '0', 'critical_threshold' => '0', 'monthly_budget' => '0', 'automatic_saving_mode' => true]);
        $downloader = Mockery::mock(WhatsAppMediaDownloaderInterface::class);
        $downloader->shouldNotReceive('download');
        $this->app->instance(WhatsAppMediaDownloaderInterface::class, $downloader);

        app(WhatsAppChannelAdapter::class)->handle($this->payload('wamid.saving.audio', '551100001006', 'saving'));

        $this->assertStringContainsString('modo de economia', $this->client->sent()[0]['text']);
        $this->assertDatabaseCount('agent_attachments', 0);
    }

    private function known(string $externalId, bool $voiceAllowed, array $permissions): User
    {
        $user = User::factory()->unprivileged()->create();
        foreach ($permissions as $permission) {
            $user->permissions()->attach(Permission::query()->where('name', $permission)->firstOrFail(), ['allowed' => true]);
        }
        $user->locations()->attach($this->location);
        UserExternalIdentity::query()->create(['user_id' => $user->id, 'channel' => 'whatsapp', 'external_user_id' => $externalId, 'status' => 'approved', 'active' => true, 'structured_commands_allowed' => true, 'voice_allowed' => $voiceAllowed]);

        return $user;
    }

    private function payload(string $messageId, string $from, string $mediaId): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'phone-test'], 'messages' => [['id' => $messageId, 'from' => $from, 'timestamp' => '1786636800', 'type' => 'audio', 'audio' => ['id' => $mediaId, 'mime_type' => 'audio/ogg']]]]]]]]];
    }
}
