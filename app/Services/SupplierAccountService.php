<?php

namespace App\Services;

use App\Enums\SupplierLedgerType;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Sole writer of suppliers.balance (what the store owes the supplier).
 * credit increases the balance, debit decreases it.
 */
class SupplierAccountService
{
    public function post(
        Supplier $supplier,
        SupplierLedgerType $type,
        string $debit,
        string $credit,
        string $description,
        User $user,
        ?Model $reference = null,
    ): SupplierLedgerEntry {
        return DB::transaction(function () use ($supplier, $type, $debit, $credit, $description, $user, $reference) {
            $locked = Supplier::query()->withTrashed()->lockForUpdate()->findOrFail($supplier->id);
            $newBalance = Money::sub(Money::add($locked->balance, $credit), $debit);

            DB::table('suppliers')->where('id', $locked->id)->update(['balance' => $newBalance, 'updated_at' => now()]);
            $supplier->setRawAttributes(array_merge($supplier->getAttributes(), ['balance' => $newBalance]), true);

            return SupplierLedgerEntry::query()->create([
                'supplier_id' => $locked->id,
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
