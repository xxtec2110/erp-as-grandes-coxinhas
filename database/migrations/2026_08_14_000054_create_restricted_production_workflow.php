<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_user_policies', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->boolean('active')->default(true);
            $t->boolean('restricted')->default(true);
            $t->time('entry_time')->nullable();
            $t->time('briefing_time');
            $t->time('alert_time');
            $t->time('cutoff_time')->default('23:59:59');
            $t->boolean('notify_regularization')->default(false);
            $t->timestampsTz();
            $t->unique(['user_id', 'location_id']);
        });
        Schema::create('production_submissions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('production_user_policy_id')->constrained()->cascadeOnDelete();
            $t->date('operation_date')->index();
            $t->string('status', 40)->index();
            $t->foreignId('agent_attachment_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('pending_agent_action_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('production_order_id')->nullable()->constrained()->nullOnDelete();
            $t->jsonb('interpretation')->nullable();
            $t->string('attachment_hash', 64)->nullable();
            $t->boolean('briefing_sent')->default(false);
            $t->timestampTz('briefing_sent_at')->nullable();
            $t->boolean('alert_sent')->default(false);
            $t->timestampTz('alert_sent_at')->nullable();
            $t->boolean('submitted_after_alert')->default(false);
            $t->timestampTz('confirmed_at')->nullable();
            $t->timestampTz('file_deleted_at')->nullable();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('late_reason')->nullable();
            $t->string('idempotency_key')->unique();
            $t->timestampsTz();
            $t->unique(['production_user_policy_id', 'operation_date'], 'production_submission_daily_unique');
        });
        foreach (['pdv.manage' => 'Gerenciar integrações de PDV', 'production.restricted.manage' => 'Gerenciar produção restrita', 'agent.usage.view' => 'Consultar uso do Agente por usuário'] as $name => $label) {
            $id = DB::table('permissions')->insertGetId(['name' => $name, 'label' => $label, 'group' => strtok($name, '.'), 'created_at' => now(), 'updated_at' => now()]);
            $role = DB::table('roles')->where('name', 'administrator')->value('id');
            if ($role) {
                DB::table('permission_role')->insert(['permission_id' => $id, 'role_id' => $role]);
            }
        }
    }

    public function down(): void
    {
        foreach (['pdv.manage', 'production.restricted.manage', 'agent.usage.view'] as $name) {
            $id = DB::table('permissions')->where('name', $name)->value('id');
            if ($id) {
                DB::table('permission_role')->where('permission_id', $id)->delete();
                DB::table('user_permissions')->where('permission_id', $id)->delete();
                DB::table('permissions')->where('id', $id)->delete();
            }
        }Schema::dropIfExists('production_submissions');
        Schema::dropIfExists('production_user_policies');
    }
};
