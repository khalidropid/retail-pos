<?php

namespace App\Services;

use App\Enums\CashDirection;
use App\Enums\CashMovementType;
use App\Enums\CustomerLedgerType;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\Payments\PaymentDetailsResolver;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ReturnService
{
    public function __construct(
        private NumberingService $numbers,
        private InventoryService $inventory,
        private CustomerAccountService $accounts,
        private CashRegisterService $registers,
        private AuditLogger $audit,
    ) {}

    /**
     * Return some or all goods of an invoice without touching the original.
     *
     * The returned value first reduces what is still owed on the invoice;
     * anything above that (the customer had already paid it) is refunded.
     *
     * @param  array<int, array{sale_item_id:int, quantity:string|int|float}>  $lines
     */
    public function returnSale(Sale $sale, array $lines, string $reason, User $user, ?int $refundMethodId = null, int|string|null $refundAccountTypeId = null, ?string $refundReference = null): SaleReturn
    {
        if (! $user->can('returns.create')) {
            throw new AuthorizationException('ليس لديك صلاحية إجراء المرتجعات.');
        }
        if (trim($reason) === '') {
            throw new BusinessException('يجب ذكر سبب المرتجع.');
        }

        $lines = array_values(array_filter($lines, fn ($l) => Money::isPositive($l['quantity'] ?? 0)));
        if ($lines === []) {
            throw new BusinessException('أدخل كمية مرتجعة لصنف واحد على الأقل.');
        }

        return DB::transaction(function () use ($sale, $lines, $reason, $user, $refundMethodId, $refundAccountTypeId, $refundReference) {
            // Lock order: customer -> sale -> items/products -> register.
            if ($sale->customer_id) {
                Customer::query()->withTrashed()->lockForUpdate()->findOrFail($sale->customer_id);
            }
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            if ($sale->isCancelled()) {
                throw new BusinessException('لا يمكن إرجاع أصناف من فاتورة ملغاة.');
            }

            $items = SaleItem::query()->where('sale_id', $sale->id)->lockForUpdate()->get()->keyBy('id');
            $products = $this->inventory->lockProducts($items->pluck('product_id')->all());

            $return = SaleReturn::query()->create([
                'number' => $this->numbers->next('sale_return'),
                'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id,
                'user_id' => $user->id,
                'returned_at' => now(),
                // Placeholder amounts satisfy the CHECK constraint; the real
                // totals are written below in the same transaction.
                'subtotal' => 0, 'tax_amount' => 0, 'total' => 0.01, 'debt_reduction' => 0.01,
                'reason' => $reason,
            ]);

            $total = '0';
            $tax = '0';

            foreach ($lines as $line) {
                /** @var SaleItem|null $item */
                $item = $items->get($line['sale_item_id']);
                if (! $item) {
                    throw new BusinessException('الصنف المحدد لا ينتمي إلى هذه الفاتورة.');
                }

                $qty = Money::qty($line['quantity']);
                $returnable = $item->returnableQuantity();
                if (Money::gt($qty, $returnable)) {
                    throw new BusinessException(sprintf(
                        'لا يمكن إرجاع %s من "%s". المباع: %s، تم إرجاع: %s، المتاح للإرجاع: %s.',
                        Money::formatQty($qty), $item->product_name, Money::formatQty($item->quantity),
                        Money::formatQty($item->returned_quantity), Money::formatQty($returnable),
                    ));
                }

                [$lineTotal, $lineTax] = $this->lineValue($item, $qty);

                $return->items()->create([
                    'sale_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $qty,
                    'unit_price' => $item->unit_price,
                    'unit_cost' => $item->unit_cost,
                    'tax_amount' => $lineTax,
                    'line_total' => $lineTotal,
                ]);

                $item->update(['returned_quantity' => Money::qty(Money::of($item->returned_quantity)->plus(Money::of($qty)))]);

                $this->inventory->move($products->get($item->product_id), $qty, StockMovementType::SaleReturn, $user, $return,
                    "مرتجع {$return->number} من الفاتورة {$sale->number}", $item->unit_cost);

                $total = Money::add($total, $lineTotal);
                $tax = Money::add($tax, $lineTax);
            }

            if (! Money::isPositive($total)) {
                throw new BusinessException('قيمة المرتجع صفر.');
            }

            // Split: reduce the outstanding debt first, refund the rest.
            $debtReduction = Money::min($total, $sale->balance_due);
            $refund = Money::sub($total, $debtReduction);

            $method = null;
            $details = null;
            $session = null;
            if (Money::isPositive($refund)) {
                $details = app(PaymentDetailsResolver::class)->resolve($refundMethodId ?? PaymentMethod::cash()->id, $refundAccountTypeId, $refundReference);
                $method = $details->method;
                if ($method->isCash()) {
                    $session = $this->registers->requireOpenSession($user);
                    $this->registers->record($session, CashMovementType::Refund, CashDirection::Out, $refund, $user, $return,
                        "رد نقدي - مرتجع {$return->number}");
                }
            }

            $return->update([
                'subtotal' => Money::sub($total, $tax),
                'tax_amount' => $tax,
                'total' => $total,
                'debt_reduction' => $debtReduction,
                'refund_amount' => $refund,
                'refund_payment_method_id' => $method?->id,
                'refund_reference' => $details?->reference,
                'cash_register_session_id' => $session?->id,
            ] + ($details ? $details->attributes('refund_') : []));

            $sale->returned_amount = Money::add($sale->returned_amount, $total);
            $sale->refunded_amount = Money::add($sale->refunded_amount, $refund);
            $sale->recalculate();
            $allReturned = $items->every(fn (SaleItem $i) => Money::isZero($i->refresh()->returnableQuantity()));
            $sale->status = $allReturned ? SaleStatus::Returned : SaleStatus::PartiallyReturned;
            $sale->save();

            if ($sale->customer_id) {
                // Debit the refund first so the running balance never dips below zero.
                if (Money::isPositive($refund)) {
                    $this->accounts->post($sale->customer, CustomerLedgerType::Refund, $refund, '0',
                        "رد مبلغ للعميل عن المرتجع {$return->number}", $user, $return);
                }
                $this->accounts->post($sale->customer, CustomerLedgerType::SaleReturn, '0', $total,
                    "مرتجع {$return->number} من الفاتورة {$sale->number}", $user, $return);
            }

            $this->audit->log('sale_returned', $return, null, [
                'number' => $return->number, 'sale' => $sale->number, 'total' => $total,
                'debt_reduction' => $debtReduction, 'refund' => $refund,
            ], $reason, userId: $user->id);

            return $return->load('items');
        });
    }

    /**
     * Value of returning $qty units of a line, using the line's real net
     * total (after discounts and tax). Returning the last remaining units
     * takes the exact remainder so rounding never drifts.
     *
     * @return array{0:string, 1:string} [line total, tax]
     */
    private function lineValue(SaleItem $item, string $qty): array
    {
        $remainingAfter = Money::of($item->returnableQuantity())->minus(Money::of($qty));

        if ($remainingAfter->isZero()) {
            $already = DB::table('sale_return_items')->where('sale_item_id', $item->id)
                ->selectRaw('COALESCE(SUM(line_total), 0) AS total, COALESCE(SUM(tax_amount), 0) AS tax')->first();

            return [Money::sub($item->line_total, $already->total), Money::sub($item->tax_amount, $already->tax)];
        }

        $ratio = Money::div($qty, $item->quantity, 10);

        return [Money::mul($item->line_total, $ratio), Money::mul($item->tax_amount, $ratio)];
    }
}
