<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo accounts. Change these passwords before using the system in a real store.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'مدير النظام', 'email' => 'admin@pos.test', 'role' => 'admin'],
            ['name' => 'سارة المديرة', 'email' => 'manager@pos.test', 'role' => 'manager'],
            ['name' => 'علي الكاشير', 'email' => 'cashier@pos.test', 'role' => 'cashier'],
            ['name' => 'حسن أمين المخزن', 'email' => 'clerk@pos.test', 'role' => 'inventory_clerk'],
        ];

        foreach ($users as $data) {
            $user = User::query()->updateOrCreate(
                ['email' => $data['email']],
                ['name' => $data['name'], 'password' => 'password', 'is_active' => true],
            );
            $user->syncRoles([$data['role']]);
        }
    }
}
