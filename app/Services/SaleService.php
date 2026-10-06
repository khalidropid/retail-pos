<?php

namespace App\Services;

use App\Enums\CashDirection;
use App\Enums\CashMovementType;
use App\Enums\CustomerLedgerType;
use App\Enums\PaymentStatus;
use App\Enums\RecordStatus;
use App\Enums\SaleStatus;
use App\Enums\SaleType;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\SaleCalculator;
use App\Support\Money;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SaleService
{
    public function __construct(
        private NumberingService $numbers,
        private InventoryService $inventory,
        private CustomerAccountService $accounts,
        private CustomerPaymentService $payments,
        private ReceivableService $receivables,
        private CashRegisterService $registers,
        private AuditLogger $audit,
    ) {}

    /**
     * Complete a sale atomically:
     *   cash:   Sale -> Items -> Stock -> Payment -> Cash movement
     *   credit: Sale -> Items -> Stock -> Receivable (ledger) [-> down payment]
     *
     * Prices, costs and tax always come from the database; client-supplied
     * prices are only honoured with the sales.edit_price permission.
     *
     * @param  array{
     *   items: array<int, array{product_id:int, quantity:string|int|float, unit_price?:string|int|float|null, discount?:string|int|float|null}>,
     *   sale_type?: string,
     *   customer_id?: int|null,
     *   payment_method_id?: int|null,
     *   paid_amount?: string|int|float|null,
     *   amount_tendered?: string|int|float|null,
     *   invoice_discount?: string|int|float|null,
     *   due_date?: string|null,
     *   override_credit_limit?: bool,
     *   notes?: string|null,
     * }  $data
     */
    public function completeSale(array $data, User $cashier): Sale
    {
        if (! $cashier->can('sales.create')) {
            throw new AuthorizationException('ليس لديك صلاحية إنشاء عملية بيع.');
        }

        $type = SaleType::tryFrom($data['sale_type'] ?? 'cash') ?? throw new BusinessException('نوع الدفع غير صالح.');
        $items = array_values(array_filter($data['items'] ?? [], fn ($i) => ! empty($i['product_id'])));
        if ($items === []) {
            throw new BusinessException('السلة فارغة. أضف منتجًا واحدًا على الأقل.');
        }
        if (count(array_unique(array_column($items, 'product_id'))) !== count($items)) {
            throw new BusinessException('يوجد منتج مكرر في السلة. عدّل الكمية بدلًا من تكرار المنتج.');
        }

        $invoiceDiscount = Money::round($data['invoice_discount'] ?? 0);
        $hasDiscount = Money::isPositive($invoiceDiscount) || collect($items)->contains(fn ($i) => Money::isPositive($i['discount'] ?? 0));
        if ($hasDiscount && ! $cashier->can('sales.apply_discount')) {
            throw new AuthorizationException('ليس لديك صلاحية تطبيق الخصم.');
        }

        return DB::transaction(function () use ($data, $cashier, $type, $items, $invoiceDiscount) {
            $session = $this->registers->currentSession($cashier);
            if (! $session && settings('pos.require_open_register', true)) {
                throw new BusinessException('يجب فتح صندوق قبل البيع.');
            }

            // Lock order everywhere: customer -> sale -> products -> register.
            $customer = null;
            if (! empty($data['customer_id'])) {
                $customer = Customer::query()->lockForUpdate()->findOrFail($data['customer_id']);
            }

            // 1) Lock products in id order and price the lines from the DB.
            $products = $this->inventory->lockProducts(array_column($items, 'product_id'));
            $calcLines = [];
            $snapshots = [];

            foreach ($items as $i => $item) {
                /** @var Product|null $product */
                $product = $products->get($item['product_id']);
                if (! $product || ! $product->is_active || $product->trashed()) {
                    throw new BusinessException('أحد المنتجات غير متاح للبيع.');
                }

                $qty = Money::qty($item['quantity'] ?? 0);
                if (! Money::isPositive($qty)) {
                    throw new BusinessException("الكمية للمنتج \"{$product->name}\" يجب أن تكون أكبر من صفر.");
                }
                if (! $product->unit->allow_decimal && ! Money::of($qty)->isEqualTo(Money::of($qty)->toScale(0, RoundingMode::Down))) {
                    throw new BusinessException("المنتج \"{$product->name}\" يُباع بالعدد الصحيح فقط.");
                }

                $price = $product->sale_price;
                if (isset($item['unit_price']) && $item['unit_price'] !== '' && ! Money::eq($item['unit_price'], $product->sale_price)) {
                    if (! $cashier->can('sales.edit_price') || ! settings('pos.allow_price_edit', true)) {
                        throw new AuthorizationException("ليس لديك صلاحية تعديل سعر \"{$product->name}\".");
                    }
                    if (Money::isNegative($item['unit_price'])) {
                        throw new BusinessException('السعر لا يمكن أن يكون سالبًا.');
                    }
                    $price = Money::round($item['unit_price']);
                }

                $calcLines[$i] = [
                    'price' => $price,
                    'quantity' => $qty,
                    'discount' => Money::round($item['discount'] ?? 0),
                    'tax_rate' => $product->tax_rate,
                ];
                $snapshots[$i] = $product;
            }

            $calc = SaleCalculator::calculate(
                $calcLines,
                $invoiceDiscount,
                (bool) settings('tax.prices_include_tax', false),
                (bool) settings('tax.enabled', true),
            );

            $maxPercent = Money::round(settings('pos.max_discount_percent', 100));
            if (Money::isPositive($calc['discount']) && Money::isPositive($calc['subtotal'])) {
                $percent = Money::div(Money::mul($calc['discount'], 100), $calc['subtotal'], 2);
                if (Money::gt($percent, $maxPercent)) {
                    throw new BusinessException("نسبة الخصم ({$percent}%) تتجاوز الحد المسموح ({$maxPercent}%).");
                }
            }

            $total = $calc['total'];

            // 2) Payment / credit rules.

            $method = null;
            $paid = Money::round($data['paid_amount'] ?? ($type === SaleType::Cash ? $total : 0));
            if (Money::isNegative($paid)) {
                throw new BusinessException('المبلغ المدفوع لا يمكن أن يكون سالبًا.');
            }
            if (Money::gt($paid, $total)) {
                throw new BusinessException('المبلغ المدفوع أكبر من إجمالي الفاتورة. استخدم "المبلغ المستلم" لحساب الباقي.');
            }

            if (Money::isPositive($paid)) {
                $method = PaymentMethod::query()->where('is_active', true)->find($data['payment_method_id'] ?? null)
                    ?? throw new BusinessException('اختر طريقة الدفع.');
                if ($method->isCash() && ! $session) {
                    throw new BusinessException('يجب فتح صندوق لاستلام النقد.');
                }
            }

            $dueDate = null;
            $overridden = false;
            $saleDate = now();

            if ($type === SaleType::Cash) {
                if (! Money::eq($paid, $total)) {
                    throw new BusinessException('في البيع النقدي يجب دفع كامل المبلغ ('.money($total).'). للبيع بالدين اختر "آجل".');
                }
            } else {
                if (! $customer) {
                    throw new BusinessException('يجب اختيار العميل في البيع الآجل.');
                }
                $creditAmount = Money::sub($total, $paid);
                if (Money::isPositive($creditAmount)) {
                    $overridden = $this->receivables->assertCreditAllowed($customer, $creditAmount, $cashier, (bool) ($data['override_credit_limit'] ?? false));
                }

                $dueDate = filled($data['due_date'] ?? null)
                    ? Carbon::parse($data['due_date'])->startOfDay()
                    : $this->receivables->dueDateFor($customer, $saleDate);
                if ($dueDate->lt(today())) {
                    throw new BusinessException('تاريخ الاستحقاق لا يمكن أن يكون في الماضي.');
                }
            }

            $tendered = null;
            $change = null;
            if ($method?->isCash() && filled($data['amount_tendered'] ?? null)) {
                $tendered = Money::round($data['amount_tendered']);
                if (Money::lt($tendered, $paid)) {
                    throw new BusinessException('المبلغ المستلم ('.money($tendered).') أقل من المطلوب ('.money($paid).').');
                }
                $change = Money::sub($tendered, $paid);
            }

            // 3) Sale + items + stock.
            $sale = new Sale([
                'number' => $this->numbers->next('sale'),
                'customer_id' => $customer?->id,
                'user_id' => $cashier->id,
                'cash_register_session_id' => $session?->id,
                'payment_method_id' => $method?->id,
                'sale_date' => $saleDate,
                'sale_type' => $type,
                'status' => SaleStatus::Completed,
                'subtotal' => $calc['subtotal'],
                'discount_amount' => $calc['discount'],
                'tax_amount' => $calc['tax'],
                'total' => $total,
                'amount_tendered' => $tendered,
                'change_given' => $change,
                'due_date' => $dueDate,
                'credit_limit_overridden' => $overridden,
                'notes' => $data['notes'] ?? null,
            ]);
            $sale->recalculate();
            $sale->save();

            foreach ($snapshots as $i => $product) {
                $line = $calc['lines'][$i];
                $sale->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'quantity' => $calcLines[$i]['quantity'],
                    'unit_price' => $calcLines[$i]['price'],
                    'list_price' => $product->sale_price,
                    'unit_cost' => $product->cost_price,
                    'discount_amount' => $line['discount'],
                    'tax_rate' => $product->tax_rate,
                    'tax_amount' => $line['tax'],
                    'line_total' => $line['total'],
                ]);

                $this->inventory->move($product, Money::of($calcLines[$i]['quantity'])->negated(), StockMovementType::Sale,
                    $cashier, $sale, "بيع - فاتورة {$sale->number}");
            }

            // 4) Receivable: the full invoice goes on the customer's account,
            //    then any payment taken now is credited against it.
            if ($customer) {
                $this->accounts->post($customer, CustomerLedgerType::Sale, $total, '0',
                    "فاتورة بيع {$sale->number}", $cashier, $sale);
            }

            if (Money::isPositive($paid)) {
                $this->payments->recordPosPayment($sale, $method, $paid, $cashier, $session);
            }

            $sale->refresh();

            $this->audit->log('sale_completed', $sale, null, [
                'number' => $sale->number, 'type' => $type->value, 'total' => $total,
                'paid' => $sale->paid_amount, 'balance_due' => $sale->balance_due,
            ], userId: $cashier->id);

            if ($overridden) {
                $this->audit->log('credit_limit_overridden', $customer, ['balance' => Money::sub($customer->refresh()->balance, Money::sub($total, $paid)), 'credit_limit' => $customer->credit_limit],
                    ['sale' => $sale->number, 'credit_amount' => Money::sub($total, $paid)], 'تجاوز الحد الائتماني عند البيع', userId: $cashier->id);
            }

            return $sale->load('items');
        });
    }

    /**
     * Cancel a whole invoice: restore stock, give back money taken at the
     * POS and reverse the receivable. Not allowed once returns exist or
     * separate account payments were applied (reverse those first).
     */
    public function cancelSale(Sale $sale, string $reason, User $user): Sale
    {
        if (! $user->can('sales.cancel')) {
            throw new AuthorizationException('ليس لديك صلاحية إلغاء المبيعات.');
        }
        if (trim($reason) === '') {
            throw new BusinessException('يجب ذكر سبب الإلغاء.');
        }

        return DB::transaction(function () use ($sale, $reason, $user) {
            if ($sale->customer_id) {
                Customer::query()->withTrashed()->lockForUpdate()->findOrFail($sale->customer_id);
            }
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($sale->isCancelled()) {
                throw new BusinessException('الفاتورة ملغاة مسبقًا.');
            }
            if (Money::isPositive($sale->returned_amount)) {
                throw new BusinessException('لا يمكن إلغاء فاتورة عليها مرتجعات. استخدم المرتجع للأصناف المتبقية.');
            }

            $allocations = $sale->allocations()->with('payment')->get()
                ->filter(fn ($a) => $a->payment->status === RecordStatus::Active);

            if ($allocations->contains(fn ($a) => $a->payment->source !== CustomerPayment::SOURCE_POS)) {
                throw new BusinessException('توجد دفعات سداد مسجلة على هذه الفاتورة. قم بعكسها أولًا ثم ألغِ الفاتورة.');
            }

            // Stock back in.
            $sale->load('items');
            $products = $this->inventory->lockProducts($sale->items->pluck('product_id')->all());
            foreach ($sale->items as $item) {
                $this->inventory->move($products->get($item->product_id), $item->quantity, StockMovementType::SaleCancellation,
                    $user, $sale, "إلغاء الفاتورة {$sale->number}", $item->unit_cost);
            }

            // Money taken at checkout goes back to the customer.
            $refundSession = null;
            foreach ($allocations as $allocation) {
                $payment = $allocation->payment;
                if ($payment->paymentMethod->isCash()) {
                    $refundSession ??= $this->registers->requireOpenSession($user);
                    $this->registers->record($refundSession, CashMovementType::SaleCancellation, CashDirection::Out, $payment->amount, $user, $sale,
                        "إرجاع نقد - إلغاء الفاتورة {$sale->number}");
                }
                if ($payment->customer_id) {
                    $this->accounts->post($payment->customer, CustomerLedgerType::PaymentReversal, $payment->amount, '0',
                        "إلغاء الدفعة {$payment->number} مع إلغاء الفاتورة", $user, $payment);
                }
                $payment->update(['status' => RecordStatus::Voided, 'voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => 'إلغاء الفاتورة: '.$reason]);
            }

            if ($sale->customer_id) {
                $this->accounts->post($sale->customer, CustomerLedgerType::SaleCancellation, '0', $sale->total,
                    "إلغاء الفاتورة {$sale->number}", $user, $sale);
            }

            $sale->update([
                'status' => SaleStatus::Cancelled,
                'payment_status' => PaymentStatus::Unpaid,
                'paid_amount' => 0,
                'balance_due' => 0,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $reason,
            ]);

            $this->audit->log('sale_cancelled', $sale, ['status' => 'completed', 'total' => $sale->total], ['status' => 'cancelled'], $reason, userId: $user->id);

            return $sale;
        });
    }
}
