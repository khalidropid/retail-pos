<?php

namespace App\Services;

use App\Enums\CustomerLedgerType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Sole writer of customers.balance. Every change produces a ledger row with
 * the running balance, so the account statement is always reconstructible.
 */
class CustomerAccountService
{
    public function post(
        Customer $customer,
        CustomerLedgerType $type,
        string $debit,
        string $credit,
        string $description,
        User $user,
        ?Model $reference = null,
    ): CustomerLedgerEntry {
        return DB::transaction(function () use ($customer, $type, $debit, $credit, $description, $user, $reference) {
            $locked = Customer::query()->withTrashed()->lockForUpdate()->findOrFail($customer->id);
            $newBalance = Money::sub(Money::add($locked->balance, $debit), $credit);

            if (Money::isNegative($newBalance)) {
                throw new BusinessException('العملية ستجعل رصيد العميل سالبًا ('.money($newBalance).'). تم رفضها.');
            }

            DB::table('customers')->where('id', $locked->id)->update(['balance' => $newBalance, 'updated_at' => now()]);
            $customer->setRawAttributes(array_merge($customer->getAttributes(), ['balance' => $newBalance]), true);

            return CustomerLedgerEntry::query()->create([
                'customer_id' => $locked->id,
                'type' => $type,
                'description' => $description,
                'debit' => Money::round($debit),
                'credit' => Money::round($credit),
                'balance_after' => $newBalance,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'user_id' => $user->id,
                'entry_date' => now(),
            ]);
        });
    }
}
