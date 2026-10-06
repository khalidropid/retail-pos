<?php

namespace App\Filament\Widgets;

use App\Services\DashboardService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StoreStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected int|array|null $columns = ['md' => 2, 'xl' => 4];

    protected function getStats(): array
    {
        $user = auth()->user();
        $financial = $user->can('dashboard.financials');
        $m = app(DashboardService::class)->metrics($financial ? null : $user->id);

        $stats = [
            Stat::make($financial ? 'مبيعات اليوم' : 'مبيعاتي اليوم', money($m['sales_today']))
                ->description($m['invoices_today'].' فاتورة')->icon('heroicon-o-shopping-cart')->color('primary'),
            Stat::make('المبيعات الآجلة اليوم', money($m['credit_today']))->icon('heroicon-o-clock')->color('warning'),
            Stat::make('المبالغ المحصلة اليوم', money($m['collected_today']))->icon('heroicon-o-banknotes')->color('success'),
            Stat::make($financial ? 'النقد في الصناديق' : 'النقد في صندوقي', money($m['cash_in_drawers']))
                ->description($m['open_registers'].' صندوق مفتوح')->icon('heroicon-o-inbox-stack'),
        ];

        if (! $financial) {
            return $stats;
        }

        $stats[] = Stat::make('صافي المبيعات اليوم', money($m['net_today']))->description('بعد المرتجعات: '.money($m['returns_today']));
        if ($user->can('reports.profit')) {
            $stats[] = Stat::make('أرباح اليوم', money($m['profit_today']))->icon('heroicon-o-arrow-trending-up')->color('success');
        }
        $stats[] = Stat::make('ديون العملاء', money($m['receivables']))->icon('heroicon-o-user-group')->color('warning');
        $stats[] = Stat::make('الديون المتأخرة', money($m['overdue']))->icon('heroicon-o-exclamation-triangle')->color('danger');
        $stats[] = Stat::make('مشتريات الشهر', money($m['purchases_month']))->icon('heroicon-o-truck');
        $stats[] = Stat::make('مصروفات اليوم', money($m['expenses_today']))->description('الشهر: '.money($m['expenses_month']))->icon('heroicon-o-receipt-refund');
        $stats[] = Stat::make('منتجات منخفضة المخزون', (string) $m['low_stock'])->icon('heroicon-o-cube')
            ->color($m['low_stock'] > 0 ? 'danger' : 'success');

        return $stats;
    }
}
