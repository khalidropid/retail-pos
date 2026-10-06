<?php

namespace Database\Seeders;

use App\Models\CashRegister;
use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class CashSetupSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['الصندوق الرئيسي', 'المدخل'], ['صندوق 2', 'الجهة الخلفية']] as [$name, $location]) {
            CashRegister::query()->firstOrCreate(['name' => $name], ['location' => $location]);
        }

        foreach (['إيجار', 'كهرباء ومياه', 'إنترنت واتصالات', 'صيانة', 'نقل وتوصيل', 'قرطاسية ومستلزمات', 'ضيافة', 'نظافة', 'أخرى'] as $name) {
            ExpenseCategory::query()->firstOrCreate(['name' => $name]);
        }
    }
}
