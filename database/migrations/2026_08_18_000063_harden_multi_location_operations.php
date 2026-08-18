<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->date('reversal_date')->nullable()->after('received_date')->index();
            $table->foreignId('reversed_by')->nullable()->after('cancelled_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('cancelled_at');
            $table->text('reversal_reason')->nullable()->after('notes');
        });

        foreach ([
            'user_locations.manage' => 'Gerenciar unidades autorizadas por usuário',
            'user_permissions.manage' => 'Gerenciar perfis e permissões por usuário',
        ] as $name => $label) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['label' => $label, 'group' => 'users', 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $administratorId = DB::table('roles')->where('name', 'administrator')->value('id');
        if ($administratorId !== null) {
            DB::table('permission_role')
                ->where('role_id', $administratorId)
                ->whereIn('permission_id', DB::table('permissions')->whereIn('name', ['user_locations.manage', 'user_permissions.manage'])->select('id'))
                ->delete();
        }
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropIndex(['reversal_date']);
            $table->dropColumn(['reversal_date', 'reversed_at', 'reversal_reason']);
        });

        // As permissões não são apagadas porque podem ter recebido atribuições administrativas.
    }
};
