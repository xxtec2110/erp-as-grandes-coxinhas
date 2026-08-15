<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('active')->default(true)->index();
        });

        Schema::table('user_external_identities', function (Blueprint $table): void {
            $table->string('phone_normalized', 20)->nullable()->after('external_user_id');
            $table->foreignId('created_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->string('welcome_status', 30)->default('not_requested');
            $table->timestamp('welcome_requested_at')->nullable();
            $table->timestamp('welcome_sent_at')->nullable();
            $table->timestamp('last_authorized_inbound_at')->nullable();
            $table->timestamp('last_system_outbound_at')->nullable();
            $table->index(['channel', 'phone_normalized', 'active'], 'external_identity_channel_phone_active_idx');
            $table->index(['user_id', 'channel', 'active'], 'external_identity_user_channel_active_idx');
        });

        DB::table('user_external_identities')->where('channel', 'whatsapp')->orderBy('id')->each(function (object $identity): void {
            $digits = preg_replace('/\D+/', '', (string) $identity->external_user_id) ?? '';
            if ($digits !== '') {
                DB::table('user_external_identities')->where('id', $identity->id)->update([
                    'phone_normalized' => '+'.$digits,
                    'activated_at' => $identity->active ? ($identity->approved_at ?? $identity->created_at) : null,
                ]);
            }
        });

        DB::statement('CREATE UNIQUE INDEX external_identity_active_phone_unique ON user_external_identities (channel, phone_normalized) WHERE active = true AND phone_normalized IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX external_identity_active_user_unique ON user_external_identities (user_id, channel) WHERE active = true AND user_id IS NOT NULL AND channel = 'whatsapp'");

        Schema::table('whatsapp_inbound_messages', function (Blueprint $table): void {
            $table->foreignId('user_external_identity_id')->nullable()->after('external_user_id')->constrained()->nullOnDelete();
            $table->string('provenance', 50)->default('authorized_user_inbound')->index();
        });

        Schema::table('whatsapp_outbound_messages', function (Blueprint $table): void {
            $table->string('provenance', 50)->default('system_agent_outbound')->index();
        });

        foreach ([
            'whatsapp.identities.view' => 'Consultar acessos WhatsApp',
            'whatsapp.identities.manage' => 'Gerenciar acessos WhatsApp',
            'agent.commands.use' => 'Usar comandos do Agente',
            'agent.write.use' => 'Executar operações de escrita pelo Agente',
            'agent.reports.use' => 'Consultar relatórios pelo Agente',
        ] as $name => $label) {
            DB::table('permissions')->updateOrInsert(['name' => $name], ['label' => $label, 'group' => strtok($name, '.'), 'updated_at' => now(), 'created_at' => now()]);
            $permissionId = DB::table('permissions')->where('name', $name)->value('id');
            $administratorId = DB::table('roles')->where('name', 'administrator')->value('id');
            if ($permissionId && $administratorId) {
                DB::table('permission_role')->updateOrInsert(['permission_id' => $permissionId, 'role_id' => $administratorId]);
            }
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS external_identity_active_phone_unique');
        DB::statement('DROP INDEX IF EXISTS external_identity_active_user_unique');
        Schema::table('whatsapp_outbound_messages', fn (Blueprint $table) => $table->dropColumn('provenance'));
        Schema::table('whatsapp_inbound_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_external_identity_id');
            $table->dropColumn('provenance');
        });
        Schema::table('user_external_identities', function (Blueprint $table): void {
            $table->dropIndex('external_identity_channel_phone_active_idx');
            $table->dropIndex('external_identity_user_channel_active_idx');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['phone_normalized', 'activated_at', 'deactivated_at', 'welcome_status', 'welcome_requested_at', 'welcome_sent_at', 'last_authorized_inbound_at', 'last_system_outbound_at']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('active'));
    }
};
