<?php

namespace App\Services;

use App\Enums\CustomerLedgerType;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Account statement built from the immutable customer ledger.
 */
class CustomerStatementService
{
    public function __construct(private ReceivableService $receivables) {}

    /**
     * @return array{
     *   customer: Customer, from: ?Carbon, to: ?Carbon,
     *   opening_balance: string, entries: Collection<int, CustomerLedgerEntry>,
     *   total_debit: string, total_credit: string, closing_balance: string,
     *   totals_by_type: array<string, string>
     * }
     */
    public function statement(Customer $customer, ?string $from = null, ?string $to = null): array
    {
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : null;
        $toDate = $to ? Carbon::parse($to)->endOfDay() : null;

        $opening = $fromDate
            ? (string) (CustomerLedgerEntry::query()->where('customer_id', $customer->id)
                ->where('entry_date', '<', $fromDate)->orderByDesc('entry_date')->orderByDesc('id')
                ->value('balance_after') ?? '0')
            : '0';

        $entries = CustomerLedgerEntry::query()
            ->where('customer_id', $customer->id)
            ->when($fromDate, fn ($q) => $q->where('entry_date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->where('entry_date', '<=', $toDate))
            ->with(['user', 'reference'])
            ->orderBy('entry_date')->orderBy('id')
            ->get();

        $totalDebit = $entries->reduce(fn ($c, $e) => Money::add($c, $e->debit), '0');
        $totalCredit = $entries->reduce(fn ($c, $e) => Money::add($c, $e->credit), '0');

        $byType = [];
        foreach (CustomerLedgerType::cases() as $type) {
            $rows = $entries->where('type', $type);
            $byType[$type->value] = $rows->reduce(fn ($c, $e) => Money::add($c, Money::add($e->debit, $e->credit)), '0');
        }

        return [
            'customer' => $customer,
            'from' => $fromDate,
            'to' => $toDate,
            'opening_balance' => Money::round($opening),
            'entries' => $entries,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'closing_balance' => Money::add(Money::sub($opening, $totalCredit), $totalDebit),
            'totals_by_type' => $byType,
        ];
    }

    /**
     * Customer summary (spec: customer report).
     *
     * @return array<string, string>
     */
    public function summary(Customer $customer): array
    {
        $sales = Sale::query()->where('customer_id', $customer->id)->notCancelled();

        return [
            'total_sales' => Money::round((clone $sales)->sum('total')),
            'total_payments' => Money::round(CustomerPayment::query()->active()->where('customer_id', $customer->id)->sum('amount')),
            'total_returns' => Money::round((clone $sales)->sum('returned_amount')),
            'total_debt' => Money::round($customer->balance),
            'overdue' => $this->receivables->overdueAmount($customer),
            'credit_limit' => Money::round($customer->credit_limit),
            'available_credit' => $customer->availableCredit(),
        ];
    }
}
