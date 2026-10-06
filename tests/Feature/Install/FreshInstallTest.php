<?php

namespace Tests\Feature\Install;

use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\User;
use App\Services\CashRegisterService;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

/**
 * Definition of done: the system installs from an empty database and the
 * full demo history keeps every ledger consistent.
 */
class FreshInstallTest extends TestCase
{
    public function test_full_seed_produces_consistent_books(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(100, Sale::query()->count());
        $this->assertSame(4, User::query()->count());

        // Stock = sum of the stock ledger.
        foreach (Product::query()->where('track_stock', true)->get() as $p) {
            $this->assertEquals((float) $p->stock_quantity, (float) StockMovement::query()->where('product_id', $p->id)->sum('quantity'), $p->sku);
        }

        foreach (Customer::query()->get() as $c) {
            // Customer balance = last ledger balance = sum of open invoices.
            $last = CustomerLedgerEntry::query()->where('customer_id', $c->id)->orderByDesc('id')->value('balance_after') ?? '0.00';
            $this->assertSame(Money::round($last), $c->balance, $c->name);
            $this->assertSame(Money::round(Sale::query()->where('customer_id', $c->id)->outstanding()->sum('balance_due')), $c->balance, $c->name);
        }

        foreach (Supplier::query()->get() as $s) {
            $last = SupplierLedgerEntry::query()->where('supplier_id', $s->id)->orderByDesc('id')->value('balance_after') ?? '0.00';
            $this->assertSame(Money::round($last), $s->balance, $s->name);
        }

        // Invoice paid amount = its active allocations.
        foreach (Sale::query()->notCancelled()->get() as $sale) {
            $allocated = PaymentAllocation::query()->where('sale_id', $sale->id)
                ->whereHas('payment', fn ($q) => $q->where('status', 'active'))->sum('amount');
            $this->assertSame(Money::round($allocated), $sale->paid_amount, $sale->number);
        }

        // Closed sessions: difference = actual - expected, expected recomputes.
        foreach (CashRegisterSession::query()->where('status', 'closed')->get() as $session) {
            $this->assertSame(Money::sub($session->actual_cash, $session->expected_cash), $session->difference);
            $this->assertSame($session->expected_cash, app(CashRegisterService::class)->expectedCash($session));
        }

        // Overdue debts exist in the demo data.
        $this->assertGreaterThan(0, Sale::query()->overdue()->count());
    }
}
