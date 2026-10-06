<?php

namespace App\Filament\Pages\Reports;

use App\Services\ReportService;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class SalesReports extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?string $title = 'تقارير المبيعات';

    protected static ?string $slug = 'reports/sales';

    protected static ?int $navigationSort = 1;

    public static function definitions(): array
    {
        return [
            'daily' => ['label' => 'المبيعات اليومية', 'dated' => true],
            'monthly' => ['label' => 'المبيعات الشهرية', 'dated' => true],
            'by_product' => ['label' => 'المبيعات حسب المنتج', 'dated' => true],
            'by_category' => ['label' => 'المبيعات حسب التصنيف', 'dated' => true],
            'by_cashier' => ['label' => 'المبيعات حسب الكاشير', 'dated' => true],
            'by_customer' => ['label' => 'المبيعات حسب العميل', 'dated' => true],
        ];
    }

    protected static function run(string $key, ReportService $reports, ?string $from, ?string $to): array
    {
        $profit = static::canViewProfit();

        return match ($key) {
            'daily' => $reports->salesByPeriod($from, $to, 'day', $profit),
            'monthly' => $reports->salesByPeriod($from, $to, 'month', $profit),
            'by_product' => $reports->salesByProduct($from, $to, $profit),
            'by_category' => $reports->salesByCategory($from, $to, $profit),
            'by_cashier' => $reports->salesByCashier($from, $to),
            'by_customer' => $reports->salesByCustomer($from, $to),
        };
    }
}
