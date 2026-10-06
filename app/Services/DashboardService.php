<?php

namespace App\Services;

use App\Models\CashRegisterSession;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(
        private ReceivableService $receivables,
        private ReportService $reports,
        private CashRegisterService $registers,
    ) {}

    /** @return array<string, mixed> */
    public function metrics(?int $onlyUserId = null): array
    {
        $today = [today()->startOfDay(), today()->endOfDay()];
        $month = [today()->startOfMonth(), today()->endOfDay()];

        $sales = DB::table('sales')->where('status', '!=', 'cancelled')->whereBetween('sale_date', $today)
            ->when($onlyUserId, fn ($q) => $q->where('user_id', $onlyUserId))
            ->selectRaw('COUNT(*) AS invoices, COALESCE(SUM(total), 0) AS gross')
            ->selectRaw("COALESCE(SUM(CASE WHEN sale_type = 'credit' THEN total - paid_amount ELSE 0 END), 0) AS credit")
            ->first();

        $returnsToday = DB::table('sale_returns')->whereBetween('returned_at', $today)
            ->when($onlyUserId, fn ($q) => $q->where('user_id', $onlyUserId))->sum('total');

        $collected = DB::table('customer_payments')->where('status', 'active')->whereBetween('payment_date', $today)
            ->when($onlyUserId, fn ($q) => $q->where('user_id', $onlyUserId))->sum('amount');

        $profitRows = $this->reports->salesByPeriod(today()->toDateString(), today()->toDateString(), 'day', true)['rows'];

        $openSessions = CashRegisterSession::query()->open()->when($onlyUserId, fn ($q) => $q->where('user_id', $onlyUserId))->get();
        $cash = $openSessions->reduce(fn ($c, $s) => Money::add($c, $this->registers->expectedCash($s)), '0');

        $receivables = $this->receivables->overview();

        return [
            'sales_today' => Money::round($sales->gross),
            'invoices_today' => (int) $sales->invoices,
            'credit_today' => Money::round($sales->credit),
            'returns_today' => Money::round($returnsToday),
            'net_today' => Money::sub($sales->gross, $returnsToday),
            'collected_today' => Money::round($collected),
            'profit_today' => Money::round($profitRows->first()['profit'] ?? 0),
            'receivables' => $receivables['total'],
            'overdue' => $receivables['overdue'],
            'purchases_month' => Money::round(DB::table('purchase_receipts')->whereBetween('received_at', $month)->sum('total')),
            'expenses_today' => Money::round(DB::table('expenses')->where('status', 'active')->whereDate('expense_date', today())->sum('amount')),
            'expenses_month' => Money::round(DB::table('expenses')->where('status', 'active')->whereBetween('expense_date', [$month[0]->toDateString(), $month[1]->toDateString()])->sum('amount')),
            'cash_in_drawers' => $cash,
            'open_registers' => $openSessions->count(),
            'low_stock' => Product::query()->active()->lowStock()->count(),
        ];
    }

    /** @return array{labels: array<int, string>, values: array<int, float>} */
    public function salesTrend(int $days = 14): array
    {
        $rows = DB::table('sales')->where('status', '!=', 'cancelled')
            ->where('sale_date', '>=', today()->subDays($days - 1)->startOfDay())
            ->groupByRaw('DATE(sale_date)')
            ->selectRaw('DATE(sale_date) AS d, SUM(total - returned_amount) AS net')
            ->pluck('net', 'd');

        $labels = [];
        $values = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = today()->subDays($i)->toDateString();
            $labels[] = today()->subDays($i)->format('m-d');
            $values[] = (float) ($rows[$date] ?? 0);
        }

        return ['labels' => $labels, 'values' => $values];
    }
}
