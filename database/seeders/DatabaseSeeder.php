<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
            RolesAndPermissionsSeeder::class,
            PaymentMethodSeeder::class,
            CashSetupSeeder::class,
            UserSeeder::class,
            CatalogSeeder::class,
        ]);
    }
}
