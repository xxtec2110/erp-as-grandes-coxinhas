<?php

namespace Tests\Feature;

use App\Models\AgentAttachment;
use App\Models\Location;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        Storage::fake('local');
        config()->set(['attachments.disk' => 'local', 'attachments.max_image_mb' => 1, 'attachments.max_document_mb' => 1]);
        $this->location = Location::query()->create(['name' => 'Catanduva', 'type' => 'store', 'active' => true]);
    }

    public function test_valid_pdf_jpeg_and_png_are_stored_privately_with_validated_metadata(): void
    {
        $user = $this->authorized('purchases.create');
        foreach ([$this->pdf(), $this->jpeg(), $this->png()] as $file) {
            $this->actingAs($user)->post(route('attachments.store'), $this->payload($file, 'purchase'))->assertRedirect();
        }
        $this->assertDatabaseCount('agent_attachments', 3);
        foreach (AgentAttachment::query()->get() as $attachment) {
            Storage::disk('local')->assertExists($attachment->path);
            $this->assertStringStartsWith('agent-attachments/', $attachment->path);
            $this->assertStringNotContainsString($attachment->original_name, $attachment->path);
            $this->assertSame('stored', $attachment->processing_status);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $attachment->content_hash);
        }
    }

    public function test_invalid_mime_false_extension_oversized_and_empty_files_are_rejected(): void
    {
        $user = $this->authorized('purchases.create');
        $invalid = UploadedFile::fake()->createWithContent('script.php', '<?php echo 1;');
        $falseExtension = UploadedFile::fake()->createWithContent('foto.jpg', "%PDF-1.4\n%%EOF");
        $oversized = UploadedFile::fake()->create('grande.pdf', 2048, 'application/pdf');
        $empty = UploadedFile::fake()->createWithContent('vazio.pdf', '');

        foreach (['invalid' => $invalid, 'false_extension' => $falseExtension, 'oversized' => $oversized, 'empty' => $empty] as $case => $file) {
            $response = $this->actingAs($user)->post(route('attachments.store'), $this->payload($file, 'purchase'))->assertRedirect();
            $errors = $response->getSession()->get('errors', []);
            $messages = is_array($errors) ? $errors : $errors->messages();
            $this->assertNotEmpty($messages['attachment'] ?? data_get($messages, 'default.messages.attachment'), "Caso inválido não rejeitado: {$case}");
        }
        $this->assertDatabaseCount('agent_attachments', 0);
        $this->assertDatabaseCount('agent_attachments', 0);
    }

    public function test_malicious_original_name_is_sanitized_and_never_controls_the_storage_path(): void
    {
        $user = $this->authorized('purchases.create');
        $file = UploadedFile::fake()->createWithContent('../../nota<script>.pdf', "%PDF-1.4\n%%EOF");
        $this->actingAs($user)->post(route('attachments.store'), $this->payload($file, 'purchase'))->assertRedirect();
        $attachment = AgentAttachment::query()->firstOrFail();

        $this->assertStringNotContainsString('..', $attachment->original_name);
        $this->assertStringNotContainsString('/', $attachment->original_name);
        $this->assertStringNotContainsString('<', $attachment->original_name);
        $this->assertStringNotContainsString('nota', $attachment->path);
    }

    public function test_upload_and_download_enforce_permission_and_location_scope_without_idor(): void
    {
        $owner = $this->authorized('purchases.create');
        $this->actingAs($owner)->post(route('attachments.store'), $this->payload($this->pdf(), 'purchase'))->assertRedirect();
        $attachment = AgentAttachment::query()->firstOrFail();
        $viewer = $this->authorized('purchases.view');
        $forbidden = User::factory()->unprivileged()->create();
        $otherLocation = Location::query()->create(['name' => 'Ibirá', 'type' => 'production', 'active' => true]);
        $otherViewer = $this->authorized('purchases.view', $otherLocation);

        $this->actingAs($viewer)->get(route('attachments.download', $attachment))->assertOk()->assertHeader('x-content-type-options', 'nosniff');
        $this->actingAs($forbidden)->get(route('attachments.download', $attachment))->assertForbidden();
        $this->actingAs($otherViewer)->get(route('attachments.download', $attachment))->assertForbidden();
        $this->actingAs($forbidden)->post(route('attachments.store'), $this->payload($this->pdf(), 'purchase'))->assertForbidden();
    }

    public function test_content_hash_is_idempotent_only_inside_the_same_protected_context(): void
    {
        $user = $this->authorized('purchases.create');
        $this->actingAs($user)->post(route('attachments.store'), $this->payload($this->pdf(), 'purchase'))->assertRedirect();
        $first = AgentAttachment::query()->firstOrFail()->id;
        $this->actingAs($user)->post(route('attachments.store'), $this->payload($this->pdf(), 'purchase'))->assertRedirect();
        $second = AgentAttachment::query()->firstOrFail()->id;

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('agent_attachments', 1);
        $other = $this->authorized('purchases.create', Location::query()->create(['name' => 'Outra', 'type' => 'store', 'active' => true]));
        $this->actingAs($other)->post(route('attachments.store'), [...$this->payload($this->pdf(), 'purchase'), 'location_id' => $other->locations()->firstOrFail()->id])->assertRedirect()->assertSessionHasErrors('attachment');
        $this->assertDatabaseCount('agent_attachments', 1);
    }

    private function authorized(string $permission, ?Location $location = null): User
    {
        $user = User::factory()->unprivileged()->create();
        $user->permissions()->attach(Permission::query()->where('name', $permission)->firstOrFail(), ['allowed' => true]);
        $user->locations()->sync([($location ?? $this->location)->id]);

        return $user;
    }

    private function payload(UploadedFile $file, string $purpose): array
    {
        return ['attachment' => $file, 'purpose' => $purpose, 'location_id' => $this->location->id, 'retention_type' => 'official'];
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('nota.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF");
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('imagem.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
    }

    private function jpeg(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('foto.jpg', base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EH//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EH//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EH//2Q==', true));
    }
}
