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

    public function hasOverdue(Customer $customer): bool
    {
        return Sale::query()->where('customer_id', $customer->id)->overdue()->exists();
    }

    public function overdueAmount(Customer $customer): string
    {
        return Money::round(Sale::query()->where('customer_id', $customer->id)->overdue()->sum('balance_due'));
    }
}
