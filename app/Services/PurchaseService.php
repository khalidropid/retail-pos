<?php

namespace App\Services;

use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Enums\SupplierLedgerType;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class PurchaseService
{
    public function __construct(
        private NumberingService $numbers,
        private InventoryService $inventory,
        private SupplierAccountService $accounts,
        private SupplierPaymentService $payments,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{supplier_id:int, order_date?:string, expected_date?:?string, supplier_invoice_number?:?string, notes?:?string, items:array<int, array{product_id:int, quantity:string|int|float, unit_cost:string|int|float, tax_rate?:string|int|float|null}>}  $data
     */
    public function create(array $data, User $actor): Purchase
    {
        $this->authorize($actor, 'purchases.create', 'ليس لديك صلاحية إنشاء أوامر الشراء.');

        return DB::transaction(function () use ($data, $actor) {
            $supplier = Supplier::query()->findOrFail($data['supplier_id']);
            if (! $supplier->is_active) {
                throw new BusinessException('المورد غير نشط.');
            }

            $purchase = Purchase::query()->create([
                'number' => $this->numbers->next('purchase'),
                'supplier_id' => $supplier->id,
                'user_id' => $actor->id,
                'status' => PurchaseStatus::Draft,
                'order_date' => $data['order_date'] ?? today(),
                'expected_date' => $data['expected_date'] ?? null,
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->replaceItems($purchase, $data['items'] ?? []);

            return $purchase->refresh()->load('items');
        });
    }

    public function update(Purchase $purchase, array $data, User $actor): Purchase
    {
        $this->authorize($actor, 'purchases.create', 'ليس لديك صلاحية تعديل أوامر الشراء.');

        return DB::transaction(function () use ($purchase, $data) {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if (! $purchase->isEditable()) {
                throw new BusinessException('لا يمكن تعديل أمر شراء تم استلامه أو إلغاؤه.');
            }

            $purchase->update(collect($data)->only(['supplier_id', 'order_date', 'expected_date', 'supplier_invoice_number', 'notes'])->all());
            if (isset($data['items'])) {
                $this->replaceItems($purchase, $data['items']);
            }

            return $purchase->refresh()->load('items');
        });
    }

    private function replaceItems(Purchase $purchase, array $items): void
    {
        if ($items === []) {
            throw new BusinessException('يجب إضافة صنف واحد على الأقل إلى أمر الشراء.');
        }

        $purchase->items()->delete();
        $subtotal = '0';
        $tax = '0';

        foreach ($items as $item) {
            $qty = Money::qty($item['quantity'] ?? 0);
            $cost = Money::round($item['unit_cost'] ?? 0, Money::COST_SCALE);
            if (! Money::isPositive($qty)) {
                throw new BusinessException('كمية الصنف يجب أن تكون أكبر من صفر.');
            }
            if (Money::isNegative($cost)) {
                throw new BusinessException('تكلفة الصنف لا يمكن أن تكون سالبة.');
            }

            $rate = Money::round($item['tax_rate'] ?? 0);
            $lineSubtotal = Money::mul($qty, $cost);
            $lineTax = Money::mul($lineSubtotal, Money::div($rate, 100, 6));

            $purchase->items()->create([
                'product_id' => Product::query()->findOrFail($item['product_id'])->id,
                'quantity' => $qty,
                'unit_cost' => $cost,
                'tax_rate' => $rate,
                'line_total' => Money::add($lineSubtotal, $lineTax),
            ]);

            $subtotal = Money::add($subtotal, $lineSubtotal);
            $tax = Money::add($tax, $lineTax);
        }

        $purchase->update(['subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => Money::add($subtotal, $tax)]);
    }

    public function markOrdered(Purchase $purchase, User $actor): Purchase
    {
        $this->authorize($actor, 'purchases.create', 'ليس لديك صلاحية تعديل أوامر الشراء.');

        return DB::transaction(function () use ($purchase) {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if ($purchase->status !== PurchaseStatus::Draft) {
                throw new BusinessException('يمكن تحويل المسودات فقط إلى "تم الطلب".');
            }
            $purchase->update(['status' => PurchaseStatus::Ordered]);

            return $purchase;
        });
    }

    /**
     * Receive goods against a purchase order (fully or partially).
     *
     * @param  array<int, array{purchase_item_id:int, quantity:string|int|float, unit_cost?:string|int|float|null}>  $lines
     * @param  array{amount?:string|int|float|null, payment_method_id?:int|null, reference?:?string}  $payment  optional immediate payment
     */
    public function receive(Purchase $purchase, array $lines, User $actor, ?string $notes = null, array $payment = []): PurchaseReceipt
    {
        $this->authorize($actor, 'purchases.receive', 'ليس لديك صلاحية استلام المشتريات.');

        $lines = array_values(array_filter($lines, fn ($l) => Money::isPositive($l['quantity'] ?? 0)));
        if ($lines === []) {
            throw new BusinessException('أدخل كمية مستلمة لصنف واحد على الأقل.');
        }

        $receipt = DB::transaction(function () use ($purchase, $lines, $actor, $notes) {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if (! $purchase->canReceive()) {
                throw new BusinessException('لا يمكن الاستلام على أمر شراء بحالة: '.$purchase->status->getLabel());
            }

            $items = PurchaseItem::query()->where('purchase_id', $purchase->id)->lockForUpdate()->get()->keyBy('id');
            $products = $this->inventory->lockProducts($items->pluck('product_id')->all());

            $receipt = PurchaseReceipt::query()->create([
                'number' => $this->numbers->next('purchase_receipt'),
                'purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'user_id' => $actor->id,
                'received_at' => now(),
                'subtotal' => 0, 'tax_amount' => 0, 'total' => 0,
                'notes' => $notes,
            ]);

            $subtotal = '0';
            $tax = '0';

            foreach ($lines as $line) {
                $item = $items->get($line['purchase_item_id']) ?? throw new BusinessException('صنف غير موجود في أمر الشراء.');
                $qty = Money::qty($line['quantity']);
                $remaining = $item->remainingToReceive();

                if (Money::gt($qty, $remaining)) {
                    throw new BusinessException(sprintf('الكمية المستلمة من "%s" (%s) أكبر من المتبقي (%s).',
                        $item->product->name, Money::formatQty($qty), Money::formatQty($remaining)));
                }

                $unitCost = Money::round($line['unit_cost'] ?? $item->unit_cost, Money::COST_SCALE);
                if (Money::isNegative($unitCost)) {
                    throw new BusinessException('التكلفة لا يمكن أن تكون سالبة.');
                }

                /** @var Product $product */
                $product = $products->get($item->product_id);
                $oldCost = $product->cost_price;
                $newCost = $this->inventory->newUnitCost($product, $qty, $unitCost);

                $this->inventory->move($product, $qty, StockMovementType::PurchaseReceipt, $actor, $receipt,
                    "استلام {$purchase->number}", $unitCost);

                if (! Money::eq($oldCost, $newCost)) {
                    DB::table('products')->where('id', $product->id)->update(['cost_price' => $newCost]);
                    $this->audit->log('price_changed', $product, ['cost_price' => $oldCost], ['cost_price' => $newCost],
                        description: "تحديث التكلفة من استلام {$receipt->number}", userId: $actor->id);
                }

                $lineSubtotal = Money::mul($qty, $unitCost);
                $lineTax = Money::mul($lineSubtotal, Money::div($item->tax_rate, 100, 6));

                $receipt->items()->create([
                    'purchase_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'tax_amount' => $lineTax,
                    'line_total' => Money::add($lineSubtotal, $lineTax),
                ]);

                $item->update(['received_quantity' => Money::qty(Money::of($item->received_quantity)->plus(Money::of($qty)))]);

                $subtotal = Money::add($subtotal, $lineSubtotal);
                $tax = Money::add($tax, $lineTax);
            }

            $total = Money::add($subtotal, $tax);
            $receipt->update(['subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => $total]);

            $fullyReceived = $items->every(fn (PurchaseItem $i) => Money::isZero($i->refresh()->remainingToReceive()));
            $purchase->update([
                'received_total' => Money::add($purchase->received_total, $total),
                'status' => $fullyReceived ? PurchaseStatus::Received : PurchaseStatus::PartiallyReceived,
            ]);

            // Supplier invoice: the received value becomes payable.
            $this->accounts->post($purchase->supplier, SupplierLedgerType::PurchaseReceipt, '0', $total,
                "فاتورة مورد - استلام {$receipt->number} لأمر الشراء {$purchase->number}", $actor, $receipt);

            $this->audit->log('purchase_received', $receipt, null, [
                'purchase' => $purchase->number, 'receipt' => $receipt->number, 'total' => $total,
            ], userId: $actor->id);

            return $receipt->load('items');
        });

        // Optional immediate payment. Runs after the receipt commits so a
        // payment problem never loses the stock that physically arrived.
        if (! empty($payment['amount']) && Money::isPositive($payment['amount'])) {
            $this->payments->pay(
                $purchase->supplier()->firstOrFail(),
                (string) $payment['amount'],
                (int) $payment['payment_method_id'],
                $actor,
                $purchase->id,
                $payment['reference'] ?? null,
                'دفعة عند الاستلام '.$receipt->number,
            );
        }

        return $receipt;
    }

    public function cancel(Purchase $purchase, string $reason, User $actor): Purchase
    {
        $this->authorize($actor, 'purchases.cancel', 'ليس لديك صلاحية إلغاء أوامر الشراء.');

        return DB::transaction(function () use ($purchase, $reason, $actor) {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);

            if (! Money::isZero($purchase->received_total)) {
                throw new BusinessException('لا يمكن إلغاء أمر شراء تم استلام جزء منه. استخدم مرتجع المشتريات بدلًا من ذلك.');
            }
            if ($purchase->status === PurchaseStatus::Cancelled) {
                throw new BusinessException('أمر الشراء ملغى مسبقًا.');
            }
            if (SupplierPayment::query()->where('purchase_id', $purchase->id)->where('status', 'active')->exists()) {
                throw new BusinessException('يوجد دفعات مسجلة على أمر الشراء. قم بعكسها أولًا.');
            }

            $purchase->update([
                'status' => PurchaseStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
            ]);

            $this->audit->log('purchase_cancelled', $purchase, null, ['number' => $purchase->number], $reason, userId: $actor->id);

            return $purchase;
        });
    }

    /**
     * Return received goods to the supplier.
     *
     * @param  array<int, array{purchase_item_id:int, quantity:string|int|float}>  $lines
     */
    public function returnToSupplier(Purchase $purchase, array $lines, string $reason, User $actor): PurchaseReturn
    {
        $this->authorize($actor, 'purchases.return', 'ليس لديك صلاحية مرتجع المشتريات.');

        $lines = array_values(array_filter($lines, fn ($l) => Money::isPositive($l['quantity'] ?? 0)));
        if ($lines === []) {
            throw new BusinessException('أدخل كمية مرتجعة لصنف واحد على الأقل.');
        }
        if (trim($reason) === '') {
            throw new BusinessException('يجب ذكر سبب المرتجع.');
        }

        return DB::transaction(function () use ($purchase, $lines, $reason, $actor) {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $items = PurchaseItem::query()->where('purchase_id', $purchase->id)->lockForUpdate()->get()->keyBy('id');
            $products = $this->inventory->lockProducts($items->pluck('product_id')->all());

            $return = PurchaseReturn::query()->create([
                'number' => $this->numbers->next('purchase_return'),
                'purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'user_id' => $actor->id,
                'returned_at' => now(),
                'subtotal' => 0, 'tax_amount' => 0, 'total' => 0,
                'reason' => $reason,
            ]);

            $subtotal = '0';
            $tax = '0';

            foreach ($lines as $line) {
                $item = $items->get($line['purchase_item_id']) ?? throw new BusinessException('صنف غير موجود في أمر الشراء.');
                $qty = Money::qty($line['quantity']);
                $returnable = $item->returnableQuantity();

                if (Money::gt($qty, $returnable)) {
                    throw new BusinessException(sprintf('لا يمكن إرجاع %s من "%s". المتاح للإرجاع: %s.',
                        Money::formatQty($qty), $item->product->name, Money::formatQty($returnable)));
                }

                // Value the return at the average cost actually received for this line.
                $receivedQty = DB::table('purchase_receipt_items')->where('purchase_item_id', $item->id)->sum('quantity');
                $receivedValue = DB::table('purchase_receipt_items')->where('purchase_item_id', $item->id)
                    ->selectRaw('COALESCE(SUM(quantity * unit_cost), 0) as v')->value('v');
                $unitCost = Money::isPositive($receivedQty) ? Money::div($receivedValue, $receivedQty, Money::COST_SCALE) : $item->unit_cost;

                $this->inventory->move($products->get($item->product_id), Money::of($qty)->negated(),
                    StockMovementType::PurchaseReturn, $actor, $return, "مرتجع للمورد {$return->number}", $unitCost);

                $lineSubtotal = Money::mul($qty, $unitCost);
                $lineTax = Money::mul($lineSubtotal, Money::div($item->tax_rate, 100, 6));

                $return->items()->create([
                    'purchase_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'tax_amount' => $lineTax,
                    'line_total' => Money::add($lineSubtotal, $lineTax),
                ]);

                $item->update(['returned_quantity' => Money::qty(Money::of($item->returned_quantity)->plus(Money::of($qty)))]);
                $subtotal = Money::add($subtotal, $lineSubtotal);
                $tax = Money::add($tax, $lineTax);
            }

            $total = Money::add($subtotal, $tax);
            $return->update(['subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => $total]);
            $purchase->update(['returned_total' => Money::add($purchase->returned_total, $total)]);

            $this->accounts->post($purchase->supplier, SupplierLedgerType::PurchaseReturn, $total, '0',
                "مرتجع مشتريات {$return->number} لأمر الشراء {$purchase->number}", $actor, $return);

            $this->audit->log('purchase_returned', $return, null, ['number' => $return->number, 'total' => $total], $reason, userId: $actor->id);

            return $return->load('items');
        });
    }

    private function authorize(User $actor, string $permission, string $message): void
    {
        if (! $actor->can($permission)) {
            throw new AuthorizationException($message);
        }
    }
}
