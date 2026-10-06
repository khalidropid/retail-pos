<?php

namespace Tests\Feature\Purchasing;

use App\Enums\PurchaseStatus;
use App\Exceptions\BusinessException;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\PurchaseService;
use App\Services\SupplierPaymentService;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use BuildsDomainData;

    private function makePurchase(array $items, ?Supplier $supplier = null): Purchase
    {
        $supplier ??= Supplier::factory()->create();

        return app(PurchaseService::class)->create([
            'supplier_id' => $supplier->id,
            'items' => $items,
        ], $this->userWithRole('manager'));
    }

    public function test_purchase_totals_are_computed(): void
    {
        $p1 = $this->stocked(0);
        $p2 = $this->stocked(0);

        $purchase = $this->makePurchase([
            ['product_id' => $p1->id, 'quantity' => 10, 'unit_cost' => '1500', 'tax_rate' => 0],
            ['product_id' => $p2->id, 'quantity' => 4, 'unit_cost' => '2500', 'tax_rate' => 10],
        ]);

        $this->assertSame(PurchaseStatus::Draft, $purchase->status);
        $this->assertStringStartsWith('PO-', $purchase->number);
        $this->assertSame('25000.00', $purchase->subtotal);
        $this->assertSame('1000.00', $purchase->tax_amount);
        $this->assertSame('26000.00', $purchase->total);
    }

    public function test_partial_then_full_receiving_increases_stock_and_supplier_balance(): void
    {
        $product = $this->stocked(0, ['cost_price' => '0']);
        $purchase = $this->makePurchase([['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => '1000']]);
        $item = $purchase->items->first();
        $clerk = $this->userWithRole('inventory_clerk');
        $service = app(PurchaseService::class);

        $receipt = $service->receive($purchase, [['purchase_item_id' => $item->id, 'quantity' => 4]], $clerk);

        $purchase->refresh();
        $this->assertSame(PurchaseStatus::PartiallyReceived, $purchase->status);
        $this->assertSame('4.000', $product->refresh()->stock_quantity);
        $this->assertSame('4000.00', $receipt->total);
        $this->assertSame('4000.00', $purchase->supplier->refresh()->balance);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'type' => 'purchase_receipt', 'quantity' => '4.000']);
        $this->assertDatabaseHas('supplier_ledger_entries', ['supplier_id' => $purchase->supplier_id, 'credit' => '4000.00', 'balance_after' => '4000.00']);

        $service->receive($purchase, [['purchase_item_id' => $item->id, 'quantity' => 6]], $clerk);

        $this->assertSame(PurchaseStatus::Received, $purchase->refresh()->status);
        $this->assertSame('10.000', $product->refresh()->stock_quantity);
        $this->assertSame('10000.00', $purchase->supplier->refresh()->balance);
    }

    public function test_cannot_receive_more_than_ordered(): void
    {
        $product = $this->stocked(0);
        $purchase = $this->makePurchase([['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => '100']]);

        $this->expectException(BusinessException::class);
        app(PurchaseService::class)->receive($purchase, [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => 6]], $this->userWithRole('admin'));
    }

    public function test_receiving_updates_weighted_average_cost(): void
    {
        $product = $this->stocked(10, ['cost_price' => '1000']);
        $purchase = $this->makePurchase([['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => '2000']]);

        app(PurchaseService::class)->receive($purchase, [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => 10]], $this->userWithRole('admin'));

        $this->assertSame('1500.0000', $product->refresh()->cost_price);
        $this->assertDatabaseHas('audit_logs', ['event' => 'price_changed', 'auditable_id' => $product->id]);
    }

    public function test_receiving_with_immediate_partial_payment(): void
    {
        $product = $this->stocked(0);
        $purchase = $this->makePurchase([['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => '1000']]);
        $bank = PaymentMethod::query()->where('code', 'bank_transfer')->first();

        app(PurchaseService::class)->receive($purchase, [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => 10]],
            $this->userWithRole('manager'), null, ['amount' => '3000', 'payment_method_id' => $bank->id]);

        $purchase->refresh();
        $this->assertSame('3000.00', $purchase->paid_amount);
        $this->assertSame('7000.00', $purchase->amountDue());
        $this->assertSame('7000.00', $purchase->supplier->refresh()->balance);
    }

    public function test_purchase_return_reduces_stock_and_supplier_balance(): void
    {
        $product = $this->stocked(0);
        $purchase = $this->makePurchase([['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => '1000']]);
        $item = $purchase->items->first();
        $admin = $this->userWithRole('admin');
        app(PurchaseService::class)->receive($purchase, [['purchase_item_id' => $item->id, 'quantity' => 10]], $admin);

        $return = app(PurchaseService::class)->returnToSupplier($purchase, [['purchase_item_id' => $item->id, 'quantity' => 3]], 'تالف', $admin);

        $this->assertSame('3000.00', $return->total);
        $this->assertSame('7.000', $product->refresh()->stock_quantity);
        $this->assertSame('7000.00', $purchase->supplier->refresh()->balance);

        $this->expectException(BusinessException::class);
        app(PurchaseService::class)->returnToSupplier($purchase, [['purchase_item_id' => $item->id, 'quantity' => 8]], 'x', $admin);
    }

    public function test_supplier_payment_cannot_exceed_balance_and_reversal_restores_it(): void
    {
        $product = $this->stocked(0);
        $purchase = $this->makePurchase([['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => '1000']]);
        $admin = $this->userWithRole('admin');
        app(PurchaseService::class)->receive($purchase, [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => 5]], $admin);
        $supplier = $purchase->supplier;
        $card = PaymentMethod::query()->where('code', 'card')->first();
        $service = app(SupplierPaymentService::class);

        try {
            $service->pay($supplier, '5001', $card->id, $admin);
            $this->fail('Overpayment should be rejected');
        } catch (BusinessException) {
        }

        $payment = $service->pay($supplier, '2000', $card->id, $admin, $purchase->id);
        $this->assertSame('3000.00', $supplier->refresh()->balance);

        $service->reverse($payment, 'خطأ في المبلغ', $admin);
        $this->assertSame('5000.00', $supplier->refresh()->balance);
        $this->assertSame('0.00', $purchase->refresh()->paid_amount);
        $this->assertDatabaseHas('audit_logs', ['event' => 'supplier_payment_reversed', 'reason' => 'خطأ في المبلغ']);
    }

    public function test_payments_cannot_be_deleted(): void
    {
        $product = $this->stocked(0);
        $purchase = $this->makePurchase([['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '1000']]);
        $admin = $this->userWithRole('admin');
        app(PurchaseService::class)->receive($purchase, [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => 1]], $admin);
        $payment = app(SupplierPaymentService::class)->pay($purchase->supplier, '500', PaymentMethod::query()->where('code', 'card')->value('id'), $admin);

        $this->expectException(\LogicException::class);
        SupplierPayment::query()->findOrFail($payment->id)->delete();
    }

    public function test_cancel_only_before_receiving(): void
    {
        $product = $this->stocked(0);
        $admin = $this->userWithRole('admin');
        $purchase = $this->makePurchase([['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => '100']]);

        app(PurchaseService::class)->cancel($purchase, 'لم يعد مطلوبًا', $admin);
        $this->assertSame(PurchaseStatus::Cancelled, $purchase->refresh()->status);

        $received = $this->makePurchase([['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => '100']]);
        app(PurchaseService::class)->receive($received, [['purchase_item_id' => $received->items->first()->id, 'quantity' => 1]], $admin);

        $this->expectException(BusinessException::class);
        app(PurchaseService::class)->cancel($received, 'x', $admin);
    }

    public function test_cashier_cannot_receive_purchases(): void
    {
        $product = $this->stocked(0);
        $purchase = $this->makePurchase([['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => '100']]);

        $this->expectException(AuthorizationException::class);
        app(PurchaseService::class)->receive($purchase, [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => 1]], $this->userWithRole('cashier'));
    }
}
