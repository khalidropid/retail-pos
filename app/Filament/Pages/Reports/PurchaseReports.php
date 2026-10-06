<?php

namespace App\Filament\Pages\Reports;

use App\Services\ReportService;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class PurchaseReports extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $title = 'تقارير المشتريات';

    protected static ?string $slug = 'reports/purchases';

    protected static ?int $navigationSort = 3;

    public static function definitions(): array
    {
        return [
            'purchases' => ['label' => 'المشتريات', 'dated' => true],
            'supplier_balances' => ['label' => 'أرصدة الموردين', 'dated' => false],
            'history' => ['label' => 'سجل المشتريات حسب المورد', 'dated' => true],
        ];
    }

    protected static function run(string $key, ReportService $reports, ?string $from, ?string $to): array
    {
        return match ($key) {
            'purchases' => $reports->purchases($from, $to),
            'supplier_balances' => $reports->supplierBalances(),
            'history' => $reports->purchaseHistoryBySupplier($from, $to),
        };
    }
}
