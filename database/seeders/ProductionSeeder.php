<?php

namespace Database\Seeders;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Minimal setup for a real store: no demo data, and an admin account with
 * a random password that is shown once in the console.
 */
class ProductionSeeder extends Seeder
{
    /** Set when this run created the admin (read by desktop:setup). */
    public static ?string $createdEmail = null;

    public static ?string $createdPassword = null;

    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
            RolesAndPermissionsSeeder::class,
            PaymentMethodSeeder::class,
            CashSetupSeeder::class,
        ]);

        foreach ([['قطعة', 'قطعة'], ['علبة', 'علبة'], ['طقم', 'طقم'], ['زوج', 'زوج']] as [$name, $short]) {
            Unit::query()->firstOrCreate(['name' => $name], ['short_name' => $short]);
        }

        if (User::query()->exists()) {
            return;
        }

        $password = Str::password(16, symbols: false);
        $admin = User::query()->create([
            'name' => 'مدير النظام',
            'email' => env('ADMIN_EMAIL', 'admin@example.com'),
            'password' => $password,
        ]);
        $admin->assignRole('admin');

        self::$createdEmail = $admin->email;
        self::$createdPassword = $password;

        $this->command?->warn('Admin account created: '.$admin->email);
        $this->command?->warn('Temporary password (shown once, change it after login): '.$password);
    }
}
