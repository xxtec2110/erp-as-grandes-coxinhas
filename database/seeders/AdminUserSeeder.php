<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $name = config('erp.initial_admin.name');
        $email = config('erp.initial_admin.email');
        $password = config('erp.initial_admin.password');

        if (! is_string($name) || $name === ''
            || ! is_string($email) || $email === ''
            || ! is_string($password) || $password === '') {
            throw new RuntimeException(
                'Configure ERP_ADMIN_NAME, ERP_ADMIN_EMAIL e ERP_ADMIN_PASSWORD antes de executar o AdminUserSeeder.'
            );
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'is_super_admin' => true,
                'all_locations_access' => true,
            ],
        );
        $administrator = Role::query()->where('name', 'administrator')->first();
        if ($administrator !== null) {
            $user->roles()->syncWithoutDetaching([$administrator->id]);
        }
    }
}
