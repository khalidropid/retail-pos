<?php

namespace App\Services;

use App\Enums\CashMovementType;
use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only reporting queries. Every report returns the same shape:
 *   ['columns' => [key => label], 'rows' => Collection<array>, 'totals' => [key => value], 'money' => [keys]]
 *
 * Aggregation happens in PostgreSQL; nothing loads whole tables into PHP.
 */
class ReportService
{
    /** Net revenue (ex-tax) and cost after returns, per sale item row. */
    private const NET_REVENUE_SQL = '(si.line_total - si.tax_amount) * (si.quantity - si.returned_quantity) / si.quantity';

    private const NET_COST_SQL = '(si.quantity - si.returned_quantity) * si.unit_cost';

    private const NET_QTY_SQL = '(si.quantity - si.returned_quantity)';

    private function range(?string $from, ?string $to): array
    {
        return [
            Carbon::parse($from ?? today()->startOfMonth())->startOfDay(),
            Carbon::parse($to ?? today())->endOfDay(),
        ];
    }

    private function result(array $columns, Collection $rows, array $money = [], array $sum = []): array
    {
        $totals = [];
        foreach ($sum as $key) {
            $totals[$key] = $rows->reduce(fn ($c, $r) => Money::add($c, $r[$key] ?? 0), '0');
        }

        return ['columns' => $columns, 'rows' => $rows->values(), 'totals' => $totals, 'money' => $money];
    }

    private function salesBase(Carbon $from, Carbon $to)
    {
        return DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.status', '!=', 'cancelled')
            ->whereBetween('s.sale_date', [$from, $to]);
    }

    // ------------------------------------------------------------------ sales

    public function salesByPeriod(?string $from, ?string $to, string $period, bool $withProfit): array
    {
        [$f, $t] = $this->range($from, $to);
        $format = $period === 'month' ? 'YYYY-MM' : 'YYYY-MM-DD';

        $invoices = DB::table('sales')->where('status', '!=', 'cancelled')->whereBetween('sale_date', [$f, $t])
            ->groupByRaw("to_char(sale_date, '{$format}')")
            ->selectRaw("to_char(sale_date, '{$format}') AS period")
            ->selectRaw('COUNT(*) AS invoices')
            ->selectRaw('SUM(total) AS gross')
            ->selectRaw('SUM(discount_amount) AS discount')
            ->selectRaw('SUM(tax_amount) AS tax')
            ->selectRaw('SUM(returned_amount) AS returns')
            ->selectRaw('SUM(total - returned_amount) AS net')
            ->selectRaw("SUM(CASE WHEN sale_type = 'credit' THEN total - returned_amount ELSE 0 END) AS credit_sales")
            ->get()->keyBy('period');

        $profit = $this->salesBase($f, $t)
            ->groupByRaw("to_char(s.sale_date, '{$format}')")
            ->selectRaw("to_char(s.sale_date, '{$format}') AS period")
            ->selectRaw('SUM('.self::NET_COST_SQL.') AS cost')
            ->selectRaw('SUM('.self::NET_REVENUE_SQL.') AS revenue')
            ->get()->keyBy('period');

        $rows = $invoices->sortKeys()->map(function ($r) use ($profit, $withProfit) {
            $row = [
                'period' => $r->period, 'invoices' => (int) $r->invoices, 'gross' => $r->gross, 'discount' => $r->discount,
                'tax' => $r->tax, 'returns' => $r->returns, 'net' => $r->net, 'credit_sales' => $r->credit_sales,
            ];
            if ($withProfit) {
                $p = $profit->get($r->period);
                $row['cost'] = Money::round($p->cost ?? 0);
                $row['profit'] = Money::sub($p->revenue ?? 0, $p->cost ?? 0);
            }

            return $row;
        });

        $columns = ['period' => $period === 'month' ? 'الشهر' : 'اليوم', 'invoices' => 'عدد الفواتير', 'gross' => 'إجمالي المبيعات',
            'discount' => 'الخصومات', 'tax' => 'الضريبة', 'returns' => 'المرتجعات', 'net' => 'صافي المبيعات', 'credit_sales' => 'منها آجل'];
        $money = ['gross', 'discount', 'tax', 'returns', 'net', 'credit_sales'];
        if ($withProfit) {
            $columns += ['cost' => 'التكلفة', 'profit' => 'الربح'];
            $money = [...$money, 'cost', 'profit'];
        }

        return $this->result($columns, $rows, $money, [...$money, 'invoices']);
    }

    public function salesByProduct(?string $from, ?string $to, bool $withProfit): array
    {
        [$f, $t] = $this->range($from, $to);

        $rows = $this->salesBase($f, $t)
            ->groupBy('si.product_id', 'si.product_name', 'si.sku')
            ->select('si.product_name', 'si.sku')
            ->selectRaw('SUM('.self::NET_QTY_SQL.') AS qty')
            ->selectRaw('SUM('.self::NET_REVENUE_SQL.') AS revenue')
            ->selectRaw('SUM('.self::NET_COST_SQL.') AS cost')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => array_merge(
                ['product' => $r->product_name, 'sku' => $r->sku, 'qty' => Money::formatQty($r->qty), 'revenue' => Money::round($r->revenue)],
                $withProfit ? ['cost' => Money::round($r->cost), 'profit' => Money::sub($r->revenue, $r->cost)] : [],
            ));

        $columns = ['product' => 'المنتج', 'sku' => 'SKU', 'qty' => 'الكمية الصافية', 'revenue' => 'الإيراد (بدون ضريبة)'];
        $money = ['revenue'];
        if ($withProfit) {
            $columns += ['cost' => 'التكلفة', 'profit' => 'الربح'];
            $money = [...$money, 'cost', 'profit'];
        }

        return $this->result($columns, $rows, $money, $money);
    }

    public function salesByCategory(?string $from, ?string $to, bool $withProfit): array
    {
        [$f, $t] = $this->range($from, $to);

        $rows = $this->salesBase($f, $t)
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->groupBy('c.id', 'c.name')
            ->selectRaw("COALESCE(c.name, 'بدون تصنيف') AS category")
            ->selectRaw('SUM('.self::NET_QTY_SQL.') AS qty')
            ->selectRaw('SUM('.self::NET_REVENUE_SQL.') AS revenue')
            ->selectRaw('SUM('.self::NET_COST_SQL.') AS cost')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => array_merge(
                ['category' => $r->category, 'qty' => Money::formatQty($r->qty), 'revenue' => Money::round($r->revenue)],
                $withProfit ? ['cost' => Money::round($r->cost), 'profit' => Money::sub($r->revenue, $r->cost)] : [],
            ));

        $columns = ['category' => 'التصنيف', 'qty' => 'الكمية', 'revenue' => 'الإيراد (بدون ضريبة)'];
        $money = ['revenue'];
        if ($withProfit) {
            $columns += ['cost' => 'التكلفة', 'profit' => 'الربح'];
            $money = [...$money, 'cost', 'profit'];
        }

        return $this->result($columns, $rows, $money, $money);
    }

    public function salesByCashier(?string $from, ?string $to): array
    {
        [$f, $t] = $this->range($from, $to);

        $rows = DB::table('sales as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.status', '!=', 'cancelled')->whereBetween('s.sale_date', [$f, $t])
            ->groupBy('u.id', 'u.name')
            ->select('u.name')
            ->selectRaw('COUNT(*) AS invoices, SUM(s.total - s.returned_amount) AS net, SUM(s.discount_amount) AS discount')
            ->selectRaw("SUM(CASE WHEN s.sale_type = 'credit' THEN s.total - s.returned_amount ELSE 0 END) AS credit_sales")
            ->orderByDesc('net')->get()
            ->map(fn ($r) => ['cashier' => $r->name, 'invoices' => (int) $r->invoices, 'net' => $r->net, 'discount' => $r->discount, 'credit_sales' => $r->credit_sales]);

        return $this->result(['cashier' => 'الكاشير', 'invoices' => 'الفواتير', 'net' => 'صافي المبيعات', 'discount' => 'الخصومات', 'credit_sales' => 'منها آجل'],
            $rows, ['net', 'discount', 'credit_sales'], ['net', 'discount', 'credit_sales', 'invoices']);
    }

    public function salesByCustomer(?string $from, ?string $to): array
    {
        [$f, $t] = $this->range($from, $to);

        $rows = DB::table('sales as s')->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->where('s.status', '!=', 'cancelled')->whereBetween('s.sale_date', [$f, $t])
            ->groupBy('c.id', 'c.name')
            ->selectRaw("COALESCE(c.name, 'عملاء نقديون') AS customer")
            ->selectRaw('COUNT(*) AS invoices, SUM(s.total - s.returned_amount) AS net, SUM(s.paid_amount - s.refunded_amount) AS paid, SUM(s.balance_due) AS due')
            ->orderByDesc('net')->get()
            ->map(fn ($r) => ['customer' => $r->customer, 'invoices' => (int) $r->invoices, 'net' => $r->net, 'paid' => $r->paid, 'due' => $r->due]);

        return $this->result(['customer' => 'العميل', 'invoices' => 'الفواتير', 'net' => 'صافي المشتريات', 'paid' => 'المدفوع', 'due' => 'المتبقي'],
            $rows, ['net', 'paid', 'due'], ['net', 'paid', 'due', 'invoices']);
    }

    /** @return Collection<int, array{name:string, qty:string, revenue:string}> */
    public function topProducts(int $limit = 5, ?string $from = null, ?string $to = null): Collection
    {
        [$f, $t] = $this->range($from, $to);

        return $this->salesBase($f, $t)
            ->groupBy('si.product_id', 'si.product_name')
            ->select('si.product_name')
            ->selectRaw('SUM('.self::NET_QTY_SQL.') AS qty, SUM('.self::NET_REVENUE_SQL.') AS revenue')
            ->orderByDesc('qty')->limit($limit)->get()
            ->map(fn ($r) => ['name' => $r->product_name, 'qty' => Money::formatQty($r->qty), 'revenue' => Money::round($r->revenue)]);
    }

    // -------------------------------------------------------------- inventory

    public function currentStock(bool $withCost, bool $lowOnly = false): array
    {
        $rows = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->join('units as u', 'u.id', '=', 'p.unit_id')
            ->whereNull('p.deleted_at')->where('p.track_stock', true)
            ->when($lowOnly, fn ($q) => $q->whereColumn('p.stock_quantity', '<=', 'p.reorder_level'))
            ->select('p.name', 'p.sku', 'c.name as category', 'u.short_name as unit', 'p.stock_quantity', 'p.reorder_level', 'p.cost_price', 'p.sale_price')
            ->orderBy($lowOnly ? 'p.stock_quantity' : 'p.name')
            ->get()
            ->map(fn ($r) => array_merge([
                'product' => $r->name, 'sku' => $r->sku, 'category' => $r->category ?? '-',
                'qty' => Money::formatQty($r->stock_quantity).' '.$r->unit, 'reorder' => Money::formatQty($r->reorder_level),
            ], $withCost ? [
                'cost' => Money::round($r->cost_price), 'value' => Money::mul($r->stock_quantity, $r->cost_price),
                'retail_value' => Money::mul($r->stock_quantity, $r->sale_price),
            ] : []));

        $columns = ['product' => 'المنتج', 'sku' => 'SKU', 'category' => 'التصنيف', 'qty' => 'الكمية', 'reorder' => 'حد الطلب'];
        $money = [];
        if ($withCost) {
            $columns += ['cost' => 'تكلفة الوحدة', 'value' => 'قيمة المخزون (تكلفة)', 'retail_value' => 'القيمة بسعر البيع'];
            $money = ['cost', 'value', 'retail_value'];
        }

        return $this->result($columns, $rows, $money, $withCost ? ['value', 'retail_value'] : []);
    }

    public function stockValuation(): array
    {
        $rows = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereNull('p.deleted_at')->where('p.track_stock', true)
            ->groupBy('c.id', 'c.name')
            ->selectRaw("COALESCE(c.name, 'بدون تصنيف') AS category, COUNT(*) AS products")
            ->selectRaw('SUM(p.stock_quantity * p.cost_price) AS value, SUM(p.stock_quantity * p.sale_price) AS retail_value')
            ->orderByDesc('value')->get()
            ->map(fn ($r) => ['category' => $r->category, 'products' => (int) $r->products, 'value' => Money::round($r->value),
                'retail_value' => Money::round($r->retail_value), 'margin' => Money::sub($r->retail_value, $r->value)]);

        return $this->result(['category' => 'التصنيف', 'products' => 'عدد المنتجات', 'value' => 'القيمة بالتكلفة',
            'retail_value' => 'القيمة بسعر البيع', 'margin' => 'الربح المتوقع'], $rows, ['value', 'retail_value', 'margin'], ['value', 'retail_value', 'margin', 'products']);
    }

    public function stockMovementsSummary(?string $from, ?string $to): array
    {
        [$f, $t] = $this->range($from, $to);
        $labels = StockMovementType::options();

        $rows = DB::table('stock_movements')->whereBetween('created_at', [$f, $t])
            ->groupBy('type')
            ->selectRaw('type, COUNT(*) AS movements')
            ->selectRaw('SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) AS qty_in')
            ->selectRaw('SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) AS qty_out')
            ->selectRaw('SUM(ABS(quantity) * unit_cost) AS value')
            ->get()
            ->map(fn ($r) => ['type' => $labels[$r->type] ?? $r->type, 'movements' => (int) $r->movements,
                'qty_in' => Money::formatQty($r->qty_in), 'qty_out' => Money::formatQty($r->qty_out), 'value' => Money::round($r->value)]);

        return $this->result(['type' => 'نوع الحركة', 'movements' => 'عدد الحركات', 'qty_in' => 'كمية داخلة', 'qty_out' => 'كمية خارجة', 'value' => 'القيمة بالتكلفة'],
            $rows, ['value'], ['value', 'movements']);
    }

    // -------------------------------------------------------------- purchases

    public function purchases(?string $from, ?string $to): array
    {
        [$f, $t] = $this->range($from, $to);
        $labels = PurchaseStatus::options();

        $rows = DB::table('purchases as p')->join('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->whereBetween('p.order_date', [$f->toDateString(), $t->toDateString()])
            ->orderByDesc('p.order_date')->orderByDesc('p.id')
            ->select('p.number', 'p.order_date', 's.name as supplier', 'p.status', 'p.total', 'p.received_total', 'p.returned_total', 'p.paid_amount')
            ->get()
            ->map(fn ($r) => ['number' => $r->number, 'date' => $r->order_date, 'supplier' => $r->supplier, 'status' => $labels[$r->status] ?? $r->status,
                'total' => $r->total, 'received' => $r->received_total, 'returned' => $r->returned_total, 'paid' => $r->paid_amount,
                'due' => Money::sub(Money::sub($r->received_total, $r->returned_total), $r->paid_amount)]);

        return $this->result(['number' => 'الرقم', 'date' => 'التاريخ', 'supplier' => 'المورد', 'status' => 'الحالة', 'total' => 'المطلوب',
            'received' => 'المستلم', 'returned' => 'المرتجع', 'paid' => 'المدفوع', 'due' => 'المستحق'],
            $rows, ['total', 'received', 'returned', 'paid', 'due'], ['total', 'received', 'returned', 'paid', 'due']);
    }

    public function supplierBalances(): array
    {
        $rows = DB::table('suppliers')->whereNull('deleted_at')->where('balance', '!=', 0)
            ->orderByDesc('balance')->get(['name', 'phone', 'balance'])
            ->map(fn ($r) => ['supplier' => $r->name, 'phone' => $r->phone ?? '-', 'balance' => $r->balance]);

        return $this->result(['supplier' => 'المورد', 'phone' => 'الهاتف', 'balance' => 'المستحق للمورد'], $rows, ['balance'], ['balance']);
    }

    public function purchaseHistoryBySupplier(?string $from, ?string $to): array
    {
        [$f, $t] = $this->range($from, $to);

        $rows = DB::table('purchase_receipts as r')->join('suppliers as s', 's.id', '=', 'r.supplier_id')
            ->whereBetween('r.received_at', [$f, $t])
            ->groupBy('s.id', 's.name')
            ->selectRaw('s.name AS supplier, COUNT(*) AS receipts, SUM(r.total) AS total')
            ->orderByDesc('total')->get()
            ->map(fn ($r) => ['supplier' => $r->supplier, 'receipts' => (int) $r->receipts, 'total' => $r->total]);

        return $this->result(['supplier' => 'المورد', 'receipts' => 'سندات الاستلام', 'total' => 'قيمة المستلم'], $rows, ['total'], ['total', 'receipts']);
    }

    // -------------------------------------------------------------- customers

    public function customerBalances(): array
    {
        $rows = DB::table('customers')->whereNull('deleted_at')->where('balance', '>', 0)
            ->orderByDesc('balance')->get(['name', 'phone', 'balance', 'credit_limit'])
            ->map(fn ($r) => ['customer' => $r->name, 'phone' => $r->phone ?? '-', 'balance' => $r->balance,
                'limit' => $r->credit_limit, 'available' => Money::max(Money::sub($r->credit_limit, $r->balance), '0')]);

        return $this->result(['customer' => 'العميل', 'phone' => 'الهاتف', 'balance' => 'الدين', 'limit' => 'الحد الائتماني', 'available' => 'المتاح'],
            $rows, ['balance', 'limit', 'available'], ['balance']);
    }

    public function customerPayments(?string $from, ?string $to): array
    {
        [$f, $t] = $this->range($from, $to);

        $rows = DB::table('customer_payments as p')->join('customers as c', 'c.id', '=', 'p.customer_id')
            ->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.source', 'account')->where('p.status', 'active')->whereBetween('p.payment_date', [$f, $t])
            ->orderByDesc('p.payment_date')
            ->get(['p.number', 'p.payment_date', 'c.name as customer', 'p.amount', 'm.name as method', 'u.name as user'])
            ->map(fn ($r) => ['number' => $r->number, 'date' => Carbon::parse($r->payment_date)->format('Y-m-d H:i'), 'customer' => $r->customer,
                'amount' => $r->amount, 'method' => $r->method, 'user' => $r->user]);

        return $this->result(['number' => 'السند', 'date' => 'التاريخ', 'customer' => 'العميل', 'amount' => 'المبلغ', 'method' => 'الطريقة', 'user' => 'الموظف'],
            $rows, ['amount'], ['amount']);
    }

    public function overdueInvoices(): array
    {
        $rows = DB::table('sales as s')->join('customers as c', 'c.id', '=', 's.customer_id')
            ->where('s.status', '!=', 'cancelled')->where('s.balance_due', '>', 0)
            ->whereNotNull('s.due_date')->whereDate('s.due_date', '<', today())
            ->orderBy('s.due_date')
            ->get(['s.number', 'c.name as customer', 's.total', 's.paid_amount', 's.balance_due', 's.due_date'])
            ->map(fn ($r) => ['number' => $r->number, 'customer' => $r->customer, 'total' => $r->total, 'paid' => $r->paid_amount,
                'due' => $r->balance_due, 'due_date' => $r->due_date, 'days' => (int) Carbon::parse($r->due_date)->diffInDays(today())]);

        return $this->result(['number' => 'الفاتورة', 'customer' => 'العميل', 'total' => 'الإجمالي', 'paid' => 'المدفوع', 'due' => 'المتبقي',
            'due_date' => 'الاستحقاق', 'days' => 'أيام التأخير'], $rows, ['total', 'paid', 'due'], ['total', 'paid', 'due']);
    }

    public function agingReport(): array
    {
        $aging = app(ReceivableService::class)->aging();
        $columns = ['name' => 'العميل'] + ReceivableService::AGING_BUCKETS + ['total' => 'الإجمالي'];
        $rows = $aging['rows']->map(fn ($r) => array_intersect_key($r, $columns));
        $money = [...array_keys(ReceivableService::AGING_BUCKETS), 'total'];

        return $this->result($columns, $rows, $money, $money);
    }

    // -------------------------------------------------------------- financial

    public function cashMovements(?string $from, ?string $to): array
    {
        [$f, $t] = $this->range($from, $to);
        $labels = CashMovementType::options();

        $rows = DB::table('cash_movements')->whereBetween('created_at', [$f, $t])
            ->groupBy('type')
            ->selectRaw("type, COUNT(*) AS movements, SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END) AS cash_in, SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END) AS cash_out")
            ->get()
            ->map(fn ($r) => ['type' => $labels[$r->type] ?? $r->type, 'movements' => (int) $r->movements, 'in' => $r->cash_in, 'out' => $r->cash_out,
                'net' => Money::sub($r->cash_in, $r->cash_out)]);

        return $this->result(['type' => 'النوع', 'movements' => 'الحركات', 'in' => 'داخل', 'out' => 'خارج', 'net' => 'الصافي'],
            $rows, ['in', 'out', 'net'], ['in', 'out', 'net', 'movements']);
    }

    public function expenses(?string $from, ?string $to): array
    {
        [$f, $t] = $this->range($from, $to);

        $rows = DB::table('expenses as e')->join('expense_categories as c', 'c.id', '=', 'e.expense_category_id')
            ->where('e.status', 'active')->whereBetween('e.expense_date', [$f->toDateString(), $t->toDateString()])
            ->groupBy('c.id', 'c.name')
            ->selectRaw('c.name AS category, COUNT(*) AS count, SUM(e.amount) AS amount')
            ->orderByDesc('amount')->get()
            ->map(fn ($r) => ['category' => $r->category, 'count' => (int) $r->count, 'amount' => $r->amount]);

        return $this->result(['category' => 'التصنيف', 'count' => 'العدد', 'amount' => 'المبلغ'], $rows, ['amount'], ['amount', 'count']);
    }

    public function refunds(?string $from, ?string $to): array
    {
        [$f, $t] = $this->range($from, $to);

        $rows = DB::table('sale_returns as r')->join('sales as s', 's.id', '=', 'r.sale_id')
            ->leftJoin('customers as c', 'c.id', '=', 'r.customer_id')->leftJoin('payment_methods as m', 'm.id', '=', 'r.refund_payment_method_id')
            ->whereBetween('r.returned_at', [$f, $t])->orderByDesc('r.returned_at')
            ->get(['r.number', 'r.returned_at', 's.number as sale', 'c.name as customer', 'r.total', 'r.debt_reduction', 'r.refund_amount', 'm.name as method', 'r.reason'])
            ->map(fn ($r) => ['number' => $r->number, 'date' => Carbon::parse($r->returned_at)->format('Y-m-d'), 'sale' => $r->sale,
                'customer' => $r->customer ?? 'نقدي', 'total' => $r->total, 'debt' => $r->debt_reduction, 'refund' => $r->refund_amount,
                'method' => $r->method ?? '-', 'reason' => $r->reason]);

        return $this->result(['number' => 'المرتجع', 'date' => 'التاريخ', 'sale' => 'الفاتورة', 'customer' => 'العميل', 'total' => 'القيمة',
            'debt' => 'من الدين', 'refund' => 'المردود', 'method' => 'طريقة الرد', 'reason' => 'السبب'],
            $rows, ['total', 'debt', 'refund'], ['total', 'debt', 'refund']);
    }

    public function paymentMethods(?string $from, ?string $to): array
    {
        [$f, $t] = $this->range($from, $to);

        $rows = DB::table('customer_payments as p')->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->where('p.status', 'active')->whereBetween('p.payment_date', [$f, $t])
            ->groupBy('m.id', 'm.name')
            ->selectRaw("m.name AS method, COUNT(*) AS count, SUM(CASE WHEN p.source = 'pos' THEN p.amount ELSE 0 END) AS pos, SUM(CASE WHEN p.source = 'account' THEN p.amount ELSE 0 END) AS account, SUM(p.amount) AS total")
            ->orderByDesc('total')->get()
            ->map(fn ($r) => ['method' => $r->method, 'count' => (int) $r->count, 'pos' => $r->pos, 'account' => $r->account, 'total' => $r->total]);

        return $this->result(['method' => 'طريقة الدفع', 'count' => 'العمليات', 'pos' => 'عند البيع', 'account' => 'سداد ديون', 'total' => 'الإجمالي'],
            $rows, ['pos', 'account', 'total'], ['pos', 'account', 'total', 'count']);
    }
}
