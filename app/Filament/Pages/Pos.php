<?php

namespace App\Filament\Pages;

use App\Exceptions\BusinessException;
use App\Filament\Resources\CashRegisterSessions\CashRegisterSessionResource;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentAccountType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\CashRegisterService;
use App\Services\CustomerService;
use App\Services\ReceivableService;
use App\Services\SaleService;
use App\Support\DbConflict;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Point of sale screen. The cart lives in the browser (Alpine) for speed;
 * every checkout is re-priced and validated by SaleService on the server.
 */
class Pos extends Page
{
    protected string $view = 'filament.pages.pos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'نقطة البيع';

    protected static ?string $navigationLabel = 'نقطة البيع';

    protected static ?string $title = 'نقطة البيع';

    protected static ?string $slug = 'pos';

    protected static ?int $navigationSort = -10;

    protected Width|string|null $maxContentWidth = Width::Full;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('pos.access') && $user->can('sales.create');
    }

    public function getHeading(): string
    {
        return '';
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        $user = auth()->user();
        $session = app(CashRegisterService::class)->currentSession($user);

        return [
            'categories' => Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])->toArray(),
            'paymentMethods' => PaymentMethod::query()->active()->where('available_at_pos', true)->get()
                ->map(fn ($m) => [
                    'id' => $m->id, 'name' => $m->name, 'code' => $m->code, 'isCash' => $m->isCash(),
                    'requiresAccountType' => $m->requires_account_type,
                    'requiresReference' => $m->requires_reference,
                    'requiresVerification' => $m->requires_verification,
                ])->all(),
            'accountTypes' => PaymentAccountType::query()->active()->where('available_at_pos', true)->get()
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'requiresReference' => $t->requires_reference])->all(),
            'currency' => ['symbol' => (string) settings('currency.symbol', ''), 'decimals' => (int) settings('currency.decimals', 0)],
            'tax' => ['enabled' => (bool) settings('tax.enabled', true), 'inclusive' => (bool) settings('tax.prices_include_tax', false)],
            'maxDiscountPercent' => (float) settings('pos.max_discount_percent', 100),
            'autoPrint' => (bool) settings('receipt.auto_print', true),
            'can' => [
                'editPrice' => $user->can('sales.edit_price') && settings('pos.allow_price_edit', true),
                'discount' => $user->can('sales.apply_discount'),
                'credit' => $user->can('sales.credit') && settings('credit.enabled', true),
                'override' => $user->can('sales.override_credit_limit') && settings('credit.allow_override', true),
                'createCustomer' => $user->can('customers.create'),
            ],
            'register' => $session ? ['number' => $session->number, 'name' => $session->register->name] : null,
            'requireRegister' => (bool) settings('pos.require_open_register', true),
            'registerUrl' => CashRegisterSessionResource::getUrl('index'),
            'perPage' => (int) settings('pos.products_per_page', 24),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function searchProducts(?string $term = null, ?int $categoryId = null): array
    {
        $this->authorizePos();

        return Product::query()
            ->active()
            ->with('unit:id,short_name,allow_decimal')
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->search($term)
            ->orderBy('name')
            ->limit((int) settings('pos.products_per_page', 24))
            ->get()
            ->map(fn (Product $p) => $this->productPayload($p))
            ->all();
    }

    /** Exact barcode / SKU lookup for the scanner. */
    public function findByCode(string $code): ?array
    {
        $this->authorizePos();
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $product = Product::query()->active()->with('unit:id,short_name,allow_decimal')
            ->where(fn ($q) => $q->where('barcode', $code)->orWhereRaw('lower(sku) = lower(?)', [$code]))
            ->first();

        return $product ? $this->productPayload($product) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function searchCustomers(string $term): array
    {
        $this->authorizePos();
        if (! auth()->user()->can('customers.view')) {
            return [];
        }

        return Customer::query()->where('is_active', true)->search($term)->orderBy('name')->limit(15)->get()
            ->map(fn (Customer $c) => $this->customerPayload($c))->all();
    }

    /** @return array<string, mixed> */
    public function createCustomer(string $name, ?string $phone = null): array
    {
        $this->authorizePos();

        try {
            validator(['name' => $name, 'phone' => $phone], [
                'name' => ['required', 'string', 'max:200'],
                'phone' => ['nullable', 'string', 'max:30'],
            ])->validate();

            if ($phone && Customer::query()->where('phone', $phone)->exists()) {
                throw new BusinessException('رقم الهاتف مسجل لعميل آخر.');
            }

            $customer = app(CustomerService::class)->create(['name' => $name, 'phone' => $phone], auth()->user());

            return ['ok' => true, 'customer' => $this->customerPayload($customer->refresh())];
        } catch (BusinessException|AuthorizationException $e) {
            return ['ok' => false, 'message' => $e->getMessage() ?: 'غير مصرح.'];
        } catch (ValidationException $e) {
            return ['ok' => false, 'message' => collect($e->errors())->flatten()->first()];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function checkout(array $payload): array
    {
        $this->authorizePos();

        try {
            $sale = app(SaleService::class)->completeSale([
                'items' => collect($payload['items'] ?? [])->map(fn ($i) => [
                    'product_id' => (int) ($i['id'] ?? 0),
                    'quantity' => (string) ($i['quantity'] ?? 0),
                    'unit_price' => ($i['priceEdited'] ?? false) ? (string) $i['price'] : null,
                    'discount' => (string) ($i['discount'] ?? 0),
                ])->values()->all(),
                'sale_type' => ($payload['saleType'] ?? 'cash') === 'credit' ? 'credit' : 'cash',
                'customer_id' => $payload['customerId'] ?? null,
                // Older clients send a single paymentMethodId instead of payment lines.
                'payment_method_id' => $payload['paymentMethodId'] ?? null,
                'payments' => ! isset($payload['payments']) ? null : collect($payload['payments'])->map(fn ($p) => [
                    'payment_method_id' => (int) ($p['methodId'] ?? 0),
                    'amount' => (string) ($p['amount'] ?? 0),
                    'account_type_id' => $p['accountTypeId'] ?? null,
                    'reference' => isset($p['reference']) ? (string) $p['reference'] : null,
                ])->values()->all(),
                'amount_tendered' => filled($payload['tendered'] ?? null) ? (string) $payload['tendered'] : null,
                'invoice_discount' => (string) ($payload['invoiceDiscount'] ?? 0),
                'due_date' => $payload['dueDate'] ?? null,
                'override_credit_limit' => (bool) ($payload['overrideCredit'] ?? false),
                'notes' => $payload['notes'] ?? null,
            ], auth()->user());

            return [
                'ok' => true,
                'sale' => [
                    'id' => $sale->id,
                    'number' => $sale->number,
                    'total' => $sale->total,
                    'paid' => $sale->paid_amount,
                    'balanceDue' => $sale->balance_due,
                    'change' => $sale->change_given,
                ],
                'receiptUrl' => route('print.sale-receipt', $sale),
            ];
        } catch (BusinessException|AuthorizationException $e) {
            return ['ok' => false, 'message' => $e->getMessage() ?: 'غير مصرح.'];
        } catch (QueryException $e) {
            if (! DbConflict::is($e)) {
                throw $e;
            }

            return ['ok' => false, 'message' => DbConflict::MESSAGE];
        }
    }

    private function authorizePos(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, mixed> */
    private function productPayload(Product $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'barcode' => $p->barcode,
            'price' => (float) $p->sale_price,
            'taxRate' => (float) $p->tax_rate,
            'stock' => $p->track_stock ? (float) $p->stock_quantity : null,
            'unit' => $p->unit?->short_name,
            'allowDecimal' => (bool) $p->unit?->allow_decimal,
            'image' => $p->image_path ? Storage::disk('public')->url($p->image_path) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function customerPayload(Customer $c): array
    {
        $showCredit = auth()->user()->can('sales.credit');

        return [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'balance' => $showCredit ? (float) $c->balance : null,
            'creditLimit' => $showCredit ? (float) $c->credit_limit : null,
            'available' => $showCredit ? (float) $c->availableCredit() : null,
            'overdue' => $showCredit ? (float) app(ReceivableService::class)->overdueAmount($c) : null,
            'creditDays' => $c->effectiveCreditDays(),
            'dueDate' => today()->addDays($c->effectiveCreditDays())->toDateString(),
        ];
    }
}
