<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    public function __construct(private AuditLogger $audit) {}

    public function create(array $data, User $actor): Customer
    {
        if (! $actor->can('customers.create')) {
            throw new AuthorizationException('ليس لديك صلاحية إضافة عملاء.');
        }

        unset($data['balance']);

        // Without the permission the new customer gets the store default limit.
        if (! $actor->can('customers.update_credit_limit')) {
            $data['credit_limit'] = settings('credit.default_limit', '0');
            unset($data['credit_days']);
        }

        $customer = Customer::query()->create($data);
        $this->audit->log('created', $customer, null, $customer->only(['name', 'phone', 'credit_limit', 'credit_days']), userId: $actor->id);

        return $customer;
    }

    public function update(Customer $customer, array $data, User $actor): Customer
    {
        if (! $actor->can('customers.update')) {
            throw new AuthorizationException('ليس لديك صلاحية تعديل العملاء.');
        }

        unset($data['balance']);

        return DB::transaction(function () use ($customer, $data, $actor) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $customer->fill($data);

            $creditChanged = $customer->isDirty('credit_limit') || $customer->isDirty('credit_days');
            if ($creditChanged && ! $actor->can('customers.update_credit_limit')) {
                throw new AuthorizationException('ليس لديك صلاحية تعديل الحد الائتماني أو مدة السداد.');
            }
            if (Money::isNegative($customer->credit_limit)) {
                throw new BusinessException('الحد الائتماني لا يمكن أن يكون سالبًا.');
            }

            $old = ['credit_limit' => $customer->getOriginal('credit_limit'), 'credit_days' => $customer->getOriginal('credit_days')];
            $customer->save();

            if ($creditChanged) {
                $this->audit->log('credit_limit_changed', $customer, $old,
                    ['credit_limit' => $customer->credit_limit, 'credit_days' => $customer->credit_days], userId: $actor->id);
            }

            $other = array_diff(array_keys($customer->getChanges()), ['credit_limit', 'credit_days', 'updated_at', 'name_normalized']);
            if ($other !== []) {
                $this->audit->log('updated', $customer,
                    collect($other)->mapWithKeys(fn ($f) => [$f => $customer->getOriginal($f)])->all(),
                    collect($other)->mapWithKeys(fn ($f) => [$f => $customer->{$f}])->all(), userId: $actor->id);
            }

            return $customer;
        });
    }

    public function delete(Customer $customer, User $actor): void
    {
        if (! $actor->can('customers.delete')) {
            throw new AuthorizationException('ليس لديك صلاحية حذف العملاء.');
        }
        if (! Money::isZero($customer->balance)) {
            throw new BusinessException('لا يمكن حذف عميل عليه رصيد مستحق ('.money($customer->balance).').');
        }

        $customer->delete();
        $this->audit->log('deleted', $customer, $customer->only(['name', 'phone']), userId: $actor->id);
    }
}
