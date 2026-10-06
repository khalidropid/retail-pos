<?php

namespace Database\Seeders;

use App\Exceptions\BusinessException;
use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CashRegisterService;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use App\Services\ExpenseService;
use App\Services\PurchaseService;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Realistic demo history over the last ~100 days, created through the
 * domain services so ledgers, stock and cash all stay consistent.
 *
 * Covers: cash sales, credit sales, partial and full payments, one payment
 * settling several invoices, overdue debts in every aging bucket, returns,
 * purchases (received / partial / ordered), supplier payments, expenses and
 * closed register sessions with differences.
 */
class DemoDataSeeder extends Seeder
{
    private User $admin;

    private User $manager;

    private User $cashier;

    private PaymentMethod $cash;

    private PaymentMethod $card;

    private PaymentMethod $bank;

    private Carbon $realToday;

    public function run(): void
    {
        if (Sale::query()->exists()) {
            $this->command?->warn('Demo data skipped: sales already exist.');

            return;
        }

        mt_srand(2026);
        $this->realToday = Carbon::today();
        $this->admin = User::query()->where('email', 'admin@pos.test')->firstOrFail();
        $this->manager = User::query()->where('email', 'manager@pos.test')->firstOrFail();
        $this->cashier = User::query()->where('email', 'cashier@pos.test')->firstOrFail();
        $this->cash = PaymentMethod::cash();
        $this->card = PaymentMethod::query()->where('code', 'card')->firstOrFail();
        $this->bank = PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();

        settings()->setMany([
            'store.name' => 'لمسة جمال للإكسسوارات ومستحضرات التجميل',
            'store.phone' => '07700000000',
            'store.address' => 'بغداد - شارع الكرادة داخل',
        ]);

        try {
            $this->purchases();
            $customers = $this->customers();
            $this->history($customers);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Move the clock to $daysAgo days before the real today. */
    private function at(int $daysAgo, int $hour = 10, int $minute = 0): void
    {
        Carbon::setTestNow($this->realToday->copy()->subDays($daysAgo)->setTime($hour, $minute));
    }

    private function purchases(): void
    {
        $this->at(100, 9);
        $suppliers = collect([
            ['name' => 'شركة الجمال لتوزيع مستحضرات التجميل', 'phone' => '07701000001', 'tax_number' => '100200300'],
            ['name' => 'مؤسسة الأناقة للإكسسوارات', 'phone' => '07701000002'],
            ['name' => 'مستودع العطور الشرقية', 'phone' => '07701000003'],
        ])->map(fn ($s) => Supplier::query()->create($s));

        $service = app(PurchaseService::class);
        $p = fn (string $sku) => Product::query()->where('sku', $sku)->firstOrFail();

        // Fully received, partly paid by bank transfer.
        $po1 = $service->create(['supplier_id' => $suppliers[0]->id, 'supplier_invoice_number' => 'R-5541', 'items' => [
            ['product_id' => $p('MKP-001')->id, 'quantity' => 60, 'unit_cost' => 9000],
            ['product_id' => $p('MKP-002')->id, 'quantity' => 30, 'unit_cost' => 20000],
            ['product_id' => $p('SKN-001')->id, 'quantity' => 80, 'unit_cost' => 5400],
        ]], $this->manager);
        $service->markOrdered($po1, $this->manager);
        $this->at(98, 11);
        $service->receive($po1, $po1->items->map(fn ($i) => ['purchase_item_id' => $i->id, 'quantity' => $i->quantity])->all(),
            $this->admin, 'استلام كامل', ['amount' => 500000, 'payment_method_id' => $this->bank->id, 'reference' => 'TRX-88213']);

        // Partially received, unpaid (supplier payable).
        $this->at(45, 10);
        $po2 = $service->create(['supplier_id' => $suppliers[1]->id, 'items' => [
            ['product_id' => $p('ACC-001')->id, 'quantity' => 20, 'unit_cost' => 27000],
            ['product_id' => $p('ACC-003')->id, 'quantity' => 50, 'unit_cost' => 6500],
        ]], $this->manager);
        $service->markOrdered($po2, $this->manager);
        $this->at(43, 12);
        $service->receive($po2, [['purchase_item_id' => $po2->items[0]->id, 'quantity' => 10]], $this->admin, 'الدفعة الأولى');

        // Ordered, not yet received.
        $this->at(3, 9);
        $po3 = $service->create(['supplier_id' => $suppliers[2]->id, 'expected_date' => today()->addDays(4)->toDateString(), 'items' => [
            ['product_id' => $p('PRF-001')->id, 'quantity' => 10, 'unit_cost' => 140000],
            ['product_id' => $p('PRF-002')->id, 'quantity' => 10, 'unit_cost' => 85000],
        ]], $this->manager);
        $service->markOrdered($po3, $this->manager);
    }

    /** @return array<string, Customer> */
    private function customers(): array
    {
        $this->at(100, 8);
        $service = app(CustomerService::class);
        $make = fn (array $d) => $service->create($d, $this->admin);

        return [
            'ahmed' => $make(['name' => 'أحمد محمود', 'phone' => '07801111111', 'address' => 'بغداد - المنصور', 'credit_limit' => 1000000, 'credit_days' => 30]),
            'mohammed' => $make(['name' => 'محمد علي', 'phone' => '07802222222', 'credit_limit' => 500000, 'credit_days' => 30]),
            'fatima' => $make(['name' => 'فاطمة حسن', 'phone' => '07803333333', 'credit_limit' => 0]),
            'alnoor' => $make(['name' => 'صالون النور للتجميل', 'phone' => '07804444444', 'tax_number' => '556677', 'credit_limit' => 2000000, 'credit_days' => 45]),
            'sara' => $make(['name' => 'سارة جاسم', 'phone' => '07805555555', 'credit_limit' => 300000, 'credit_days' => 30]),
            'ali' => $make(['name' => 'علي كريم', 'phone' => '07806666666', 'credit_limit' => 250000, 'credit_days' => 7]),
        ];
    }

    /** @param array<string, Customer> $c */
    private function history(array $c): void
    {
        $registers = app(CashRegisterService::class);
        $main = CashRegister::query()->orderBy('id')->firstOrFail();
        $second = CashRegister::query()->orderBy('id')->skip(1)->firstOrFail();

        $this->at(100, 8);
        $managerSession = $registers->openRegister($second, 200000, $this->manager, 'صندوق الإدارة');
        $session = null;
        $sessionOpenedDay = null;

        // Walk-in baskets draw from products not reserved for the scheduled credit sales below.
        $reserved = ['MKP-001', 'MKP-002', 'MKP-003', 'MKP-004', 'SKN-003', 'SKN-004', 'HAR-001', 'HAR-002', 'PRF-001', 'TLS-001',
            'ACC-001', 'ACC-002', 'ACC-006', 'NAL-001'];
        $products = Product::query()->where('track_stock', true)->where('stock_quantity', '>', 20)
            ->whereNotIn('sku', $reserved)->with('unit')->get();

        // Scheduled credit-sale events (days ago => closure).
        $events = [
            95 => fn () => $this->credit($c['ahmed'], ['PRF-001' => 1, 'MKP-004' => 1], 0),            // >90 overdue, partly paid later
            98 => fn () => $this->credit($c['ali'], ['ACC-002' => 2], 0),                                 // due after 7 days -> 90+ overdue             // 61-90 overdue
            62 => fn () => $this->credit($c['mohammed'], ['MKP-004' => 1, 'MKP-002' => 1], 20000),       // will be paid fully
            55 => fn () => $this->payment($c['mohammed'], '40000', $this->cash),
            50 => fn () => $this->credit($c['alnoor'], ['MKP-001' => 10], 0),
            48 => fn () => $this->credit($c['alnoor'], ['SKN-003' => 8], 0),
            46 => fn () => $this->credit($c['alnoor'], ['TLS-001' => 3], 0),
            75 => fn () => $this->credit($c['ahmed'], ['ACC-001' => 2, 'ACC-006' => 1], 0),              // 31-60 overdue
            35 => fn () => $this->credit($c['sara'], ['SKN-004' => 3, 'HAR-002' => 2], 20000),            // partial, overdue few days
            30 => fn () => $this->payment($c['mohammed'], 'rest', $this->bank, 'TRX-91002'),              // full payment
            25 => fn () => $this->payment($c['alnoor'], 'two_invoices', $this->bank, 'TRX-91550'),        // one payment, several invoices
            20 => fn () => $this->payment($c['ahmed'], '50000', $this->cash),                             // partial on the oldest
            12 => fn () => $this->returnFrom($c['ahmed']),                                                 // return on credit invoice
            5 => fn () => $this->credit($c['ahmed'], ['MKP-003' => 2, 'NAL-001' => 4], 0),                 // not due yet
            2 => fn () => $this->credit($c['alnoor'], ['HAR-001' => 10], 0),
        ];

        for ($day = 100; $day >= 0; $day--) {
            $this->at($day, 9);

            // Weekly register sessions for the cashier; closed with a count.
            if ($session && $sessionOpenedDay - $day >= 7) {
                $expected = $registers->expectedCash($session);
                $diff = [0, 0, -500, 0, 250, -1000][mt_rand(0, 5)];
                $registers->closeRegister($session, Money::add($expected, $diff), $this->cashier, $diff ? 'فرق عند العدّ' : null);
                $session = null;
            }
            if (! $session) {
                $session = $registers->openRegister($main, 100000, $this->cashier);
                $sessionOpenedDay = $day;
            }

            // Walk-in sales.
            foreach (range(1, mt_rand(1, 3)) as $n) {
                $this->at($day, 10 + $n, mt_rand(0, 59));
                $lines = $products->random(mt_rand(1, 3))->map(fn (Product $p) => [
                    'product_id' => $p->id,
                    'quantity' => mt_rand(1, 2),
                ])->values()->all();
                $method = mt_rand(1, 10) <= 7 ? $this->cash : $this->card;
                try {
                    app(SaleService::class)->completeSale([
                        'items' => $lines, 'sale_type' => 'cash', 'payment_method_id' => $method->id,
                        'customer_id' => mt_rand(1, 10) === 1 ? $c['fatima']->id : null,
                    ], $this->cashier);
                } catch (BusinessException) {
                    // Out of stock for a random pick: skip this basket.
                }
            }

            if (isset($events[$day])) {
                $this->at($day, 17);
                ($events[$day])();
            }

            if ($day % 30 === 0) {
                $this->at($day, 18);
                app(ExpenseService::class)->create([
                    'expense_category_id' => ExpenseCategory::query()->where('name', 'كهرباء ومياه')->value('id'),
                    'amount' => 45000, 'description' => 'فاتورة الكهرباء الشهرية', 'payment_method_id' => $this->cash->id,
                ], $this->manager);
            }
            if ($day === 60 || $day === 30 || $day === 1) {
                app(ExpenseService::class)->create([
                    'expense_category_id' => ExpenseCategory::query()->where('name', 'إيجار')->value('id'),
                    'amount' => 750000, 'description' => 'إيجار المحل', 'payment_method_id' => $this->bank->id, 'reference' => 'RENT-'.$day,
                ], $this->manager);
            }
        }

        // A cash refund today and a small café expense from the manager's drawer.
        $this->at(0, 16);
        $todaySale = app(SaleService::class)->completeSale([
            'items' => [['product_id' => Product::query()->where('sku', 'HAR-003')->value('id'), 'quantity' => 1]],
            'sale_type' => 'cash', 'payment_method_id' => $this->cash->id,
        ], $this->cashier);
        $this->at(0, 17);
        app(ReturnService::class)->returnSale($todaySale, [['sale_item_id' => $todaySale->items->first()->id, 'quantity' => 1]],
            'الجهاز لا يعمل', $this->manager, $this->cash->id);
        app(ExpenseService::class)->create([
            'expense_category_id' => ExpenseCategory::query()->where('name', 'ضيافة')->value('id'),
            'amount' => 7500, 'description' => 'شاي وقهوة', 'payment_method_id' => $this->cash->id,
        ], $this->manager);

        // Manager's long session is closed; the cashier's current session stays open.
        $registers->closeRegister($managerSession, $registers->expectedCash($managerSession), $this->manager, 'إغلاق صندوق الإدارة');
    }

    /** @param array<string, int> $skus */
    private function credit(Customer $customer, array $skus, int $downPayment): Sale
    {
        $items = collect($skus)->map(fn ($qty, $sku) => [
            'product_id' => Product::query()->where('sku', $sku)->value('id'), 'quantity' => $qty,
        ])->values()->all();

        return app(SaleService::class)->completeSale([
            'items' => $items, 'sale_type' => 'credit', 'customer_id' => $customer->id,
            'paid_amount' => $downPayment, 'payment_method_id' => $this->cash->id,
            'override_credit_limit' => true,
        ], $this->manager);
    }

    private function payment(Customer $customer, string $amount, PaymentMethod $method, ?string $reference = null): void
    {
        $customer->refresh();
        $service = app(CustomerPaymentService::class);

        if ($amount === 'rest') {
            $service->recordCustomerPayment($customer, $customer->balance, $method->id, $this->manager, null, $reference);

            return;
        }

        if ($amount === 'two_invoices') {
            $open = Sale::query()->where('customer_id', $customer->id)->outstanding()->orderBy('id')->take(2)->get();
            $allocations = $open->mapWithKeys(fn (Sale $s) => [$s->id => $s->balance_due])->all();
            $service->recordCustomerPayment($customer, Money::add(...array_values($allocations)), $method->id, $this->manager,
                $allocations, $reference, 'تسديد فاتورتين');

            return;
        }

        $service->recordCustomerPayment($customer, $amount, $method->id, $this->manager, null, $reference);
    }

    private function returnFrom(Customer $customer): void
    {
        $sale = Sale::query()->where('customer_id', $customer->id)->where('balance_due', '>', 0)->latest('id')->firstOrFail();
        $item = $sale->items->first();

        app(ReturnService::class)->returnSale($sale, [['sale_item_id' => $item->id, 'quantity' => 2]], 'عيب في المنتج', $this->manager);
    }
}
