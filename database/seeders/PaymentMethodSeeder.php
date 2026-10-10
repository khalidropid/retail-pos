<?php

namespace Database\Seeders;

use App\Models\PaymentAccountType;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            ['code' => 'cash', 'name' => 'نقدي', 'type' => 'cash', 'sort_order' => 1],
            ['code' => 'card', 'name' => 'الحسابات', 'type' => 'account', 'sort_order' => 2, 'requires_account_type' => true],
            ['code' => 'bank_transfer', 'name' => 'التحويلات البنكية', 'type' => 'bank_transfer', 'sort_order' => 3,
                'requires_reference' => true, 'requires_verification' => true],
        ];

        foreach ($methods as $method) {
            PaymentMethod::query()->firstOrCreate(['code' => $method['code']], $method);
        }

        // Account types are also inserted by the migration (for upgrades);
        // this keeps fresh installs and tests in sync with that list.
        $migration = require database_path('migrations/2026_10_11_000010_payment_accounts_and_details.php');
        foreach ($migration::accountTypes() as $i => $type) {
            PaymentAccountType::query()->firstOrCreate(['code' => $type['code']], $type + ['sort_order' => $i + 1]);
        }
    }
}
