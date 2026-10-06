<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Credit rules for customers (limits, terms, overdue).
 */
class ReceivableService
{
    public function __construct(private AuditLogger $audit) {}

    public function dueDateFor(Customer $customer, CarbonInterface $saleDate): Carbon
    {
        return Carbon::instance($saleDate)->startOfDay()->addDays($customer->effectiveCreditDays());
    }

    /**
     * Validate that $creditAmount can be added to the customer's debt.
     * The customer row must already be locked by the caller.
     *
     * @return bool whether the limit was overridden
     */
    public function assertCreditAllowed(Customer $customer, string $creditAmount, User $user, bool $requestOverride = false): bool
    {
        if (! settings('credit.enabled', true)) {
            throw new BusinessException('البيع الآجل معطّل في إعدادات المتجر.');
        }
        if (! $user->can('sales.credit')) {
            throw new AuthorizationException('ليس لديك صلاحية البيع الآجل.');
        }
        if (! $customer->is_active || $customer->trashed()) {
            throw new BusinessException('العميل غير نشط، لا يمكن البيع له بالآجل.');
        }

        $canOverride = $requestOverride
            && settings('credit.allow_override', true)
            && $user->can('sales.override_credit_limit');

        if (settings('credit.block_on_overdue', false) && $this->hasOverdue($customer)) {
            if (! $canOverride) {
                throw new BusinessException("العميل \"{$customer->name}\" لديه فواتير متأخرة السداد. لا يمكن البيع له بالآجل قبل التسديد.");
            }
        }

        $projected = Money::add($customer->balance, $creditAmount);
        if (Money::lte($projected, $customer->credit_limit)) {
            return false;
        }

        if ($canOverride) {
            return true;
        }

        throw new BusinessException(sprintf(
            'تجاوز الحد الائتماني للعميل "%s": الدين الحالي %s + قيمة البيع الآجل %s = %s، وهو أكبر من الحد الائتماني %s (المتاح: %s).',
            $customer->name,
            money($customer->balance),
            money($creditAmount),
            money($projected),
            money($customer->credit_limit),
            money($customer->availableCredit()),
        ));
    }

    public const AGING_BUCKETS = [
        'current' => 'غير مستحقة بعد',
        'd0_30' => '1 - 30 يوم',
        'd31_60' => '31 - 60 يوم',
        'd61_90' => '61 - 90 يوم',
        'd90_plus' => 'أكثر من 90 يوم',
    ];

    /**
     * Aging of open invoices by days past due date, per customer.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, string>}
     */
    public function aging(?CarbonInterface $asOf = null): array
    {
        $asOf = Carbon::instance($asOf ?? today())->startOfDay();
        $asOfSql = $asOf->toDateString();

        $rows = DB::table('sales')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sales.status', '!=', 'cancelled')
            ->where('sales.balance_due', '>', 0)
            ->groupBy('customers.id', 'customers.name', 'customers.phone', 'customers.credit_limit')
            ->select('customers.id', 'customers.name', 'customers.phone', 'customers.credit_limit')
            ->selectRaw('SUM(CASE WHEN sales.due_date IS NULL OR sales.due_date >= ?::date THEN sales.balance_due ELSE 0 END) AS current', [$asOfSql])
            ->selectRaw('SUM(CASE WHEN ?::date - sales.due_date BETWEEN 1 AND 30 THEN sales.balance_due ELSE 0 END) AS d0_30', [$asOfSql])
            ->selectRaw('SUM(CASE WHEN ?::date - sales.due_date BETWEEN 31 AND 60 THEN sales.balance_due ELSE 0 END) AS d31_60', [$asOfSql])
            ->selectRaw('SUM(CASE WHEN ?::date - sales.due_date BETWEEN 61 AND 90 THEN sales.balance_due ELSE 0 END) AS d61_90', [$asOfSql])
            ->selectRaw('SUM(CASE WHEN ?::date - sales.due_date > 90 THEN sales.balance_due ELSE 0 END) AS d90_plus', [$asOfSql])
            ->selectRaw('SUM(sales.balance_due) AS total')
            ->selectRaw('COUNT(*) AS invoices')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => (array) $r + ['overdue' => Money::add($r->d0_30, $r->d31_60, $r->d61_90, $r->d90_plus)]);

        $totals = [];
        foreach ([...array_keys(self::AGING_BUCKETS), 'overdue', 'total'] as $key) {
            $totals[$key] = $rows->reduce(fn ($c, $r) => Money::add($c, $r[$key]), '0');
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /** @return array{total:string, current:string, overdue:string, customers:int, overdue_customers:int} */
    public function overview(): array
    {
        $open = Sale::query()->outstanding();
        $overdue = Sale::query()->overdue();

        return [
            'total' => Money::round((clone $open)->sum('balance_due')),
            'overdue' => Money::round((clone $overdue)->sum('balance_due')),
            'current' => Money::sub((clone $open)->sum('balance_due'), (clone $overdue)->sum('balance_due')),
            'customers' => (clone $open)->distinct()->count('customer_id'),
            'overdue_customers' => (clone $overdue)->distinct()->count('customer_id'),
        ];
    }

    public function hasOverdue(Customer $customer): bool
    {
        return Sale::query()->where('customer_id', $customer->id)->overdue()->exists();
    }

    public function overdueAmount(Customer $customer): string
    {
        return Money::round(Sale::query()->where('customer_id', $customer->id)->overdue()->sum('balance_due'));
    }
}
