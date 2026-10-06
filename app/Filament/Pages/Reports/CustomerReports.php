<?php

namespace App\Filament\Pages\Reports;

use App\Services\ReportService;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class CustomerReports extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $title = 'تقارير العملاء والذمم';

    protected static ?string $slug = 'reports/customers';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('reports.view') && $user->can('customers.view_debts');
    }

    public static function definitions(): array
    {
        return [
            'balances' => ['label' => 'أرصدة العملاء', 'dated' => false],
            'payments' => ['label' => 'دفعات العملاء', 'dated' => true],
            'overdue' => ['label' => 'الذمم المتأخرة', 'dated' => false],
            'aging' => ['label' => 'أعمار الديون', 'dated' => false],
        ];
    }

    protected static function run(string $key, ReportService $reports, ?string $from, ?string $to): array
    {
        return match ($key) {
            'balances' => $reports->customerBalances(),
            'payments' => $reports->customerPayments($from, $to),
            'overdue' => $reports->overdueInvoices(),
            'aging' => $reports->agingReport(),
        };
    }
}
