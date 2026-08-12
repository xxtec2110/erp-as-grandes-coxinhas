<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'locations.view' => 'Consultar unidades', 'locations.create' => 'Cadastrar unidades', 'locations.update' => 'Editar unidades',
            'ingredients.view' => 'Consultar insumos', 'ingredients.create' => 'Cadastrar insumos', 'ingredients.update' => 'Editar insumos',
            'preparations.view' => 'Consultar preparos', 'preparations.create' => 'Cadastrar preparos', 'preparations.update' => 'Editar preparos',
        ];
        foreach ($permissions as $name => $label) {
            Permission::query()->updateOrCreate(['name' => $name], ['label' => $label, 'group' => strtok($name, '.')]);
        }
        Role::query()->where('name', 'administrator')->first()?->permissions()->syncWithoutDetaching(Permission::query()->whereIn('name', array_keys($permissions))->pluck('id'));
        $text = Permission::query()->where('name', 'agent.text.use')->first();
        if ($text !== null) {
            Role::query()->where('name', '!=', 'administrator')->get()->each(fn (Role $role) => $role->permissions()->detach($text));
        }
    }

    public function down(): void
    {
        // Não apaga permissões que podem ter recebido atribuições administrativas.
    }
};
