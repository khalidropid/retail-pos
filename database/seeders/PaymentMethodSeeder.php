<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            ['code' => 'cash', 'name' => 'نقدي', 'type' => 'cash', 'sort_order' => 1],
            ['code' => 'card', 'name' => 'بطاقة', 'type' => 'card', 'sort_order' => 2],
            ['code' => 'bank_transfer', 'name' => 'تحويل بنكي', 'type' => 'bank_transfer', 'sort_order' => 3],
            ['code' => 'wallet', 'name' => 'محفظة إلكترونية', 'type' => 'other', 'sort_order' => 4],
        ];

        foreach ($methods as $method) {
            PaymentMethod::query()->firstOrCreate(['code' => $method['code']], $method);
        }
    }
}
