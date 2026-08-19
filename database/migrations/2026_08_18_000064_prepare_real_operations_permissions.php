<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach ([
            'operations.readiness.view' => 'Consultar preparação para operação real',
            'stock.opening_balance' => 'Confirmar estoque inicial',
        ] as $name => $label) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['label' => $label, 'group' => strtok($name, '.'), 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $administratorId = DB::table('roles')->where('name', 'administrator')->value('id');
        if ($administratorId !== null) {
            $permissionIds = DB::table('permissions')->whereIn('name', [
                'operations.readiness.view',
                'stock.opening_balance',
            ])->pluck('id');
            DB::table('permission_role')->where('role_id', $administratorId)->whereIn('permission_id', $permissionIds)->delete();
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('name', [
            'operations.readiness.view',
            'stock.opening_balance',
        ])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('user_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
