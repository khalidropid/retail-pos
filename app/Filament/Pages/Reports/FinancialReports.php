<?php

namespace App\Filament\Pages\Reports;

use App\Services\ReportService;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class FinancialReports extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $title = 'التقارير المالية التشغيلية';

    protected static ?string $slug = 'reports/financial';

    protected static ?int $navigationSort = 5;

    public static function definitions(): array
    {
        return [
            'cash' => ['label' => 'الحركات النقدية', 'dated' => true],
            'expenses' => ['label' => 'المصروفات حسب التصنيف', 'dated' => true],
            'refunds' => ['label' => 'المرتجعات والمبالغ المردودة', 'dated' => true],
            'methods' => ['label' => 'المقبوضات حسب طريقة الدفع', 'dated' => true],
        ];
    }

    protected static function run(string $key, ReportService $reports, ?string $from, ?string $to): array
    {
        return match ($key) {
            'cash' => $reports->cashMovements($from, $to),
            'expenses' => $reports->expenses($from, $to),
            'refunds' => $reports->refunds($from, $to),
            'methods' => $reports->paymentMethods($from, $to),
        };
    }
}
