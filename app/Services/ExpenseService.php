<?php

namespace App\Services;

use App\Enums\CashDirection;
use App\Enums\CashMovementType;
use App\Enums\RecordStatus;
use App\Exceptions\BusinessException;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    public function __construct(
        private NumberingService $numbers,
        private CashRegisterService $registers,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{expense_category_id:int, amount:string|int|float, description:string, expense_date?:string, payment_method_id:int, reference?:?string}  $data
     */
    public function create(array $data, User $user): Expense
    {
        if (! $user->can('expenses.create')) {
            throw new AuthorizationException('ليس لديك صلاحية تسجيل المصروفات.');
        }

        $amount = Money::round($data['amount'] ?? 0);
        if (! Money::isPositive($amount)) {
            throw new BusinessException('مبلغ المصروف يجب أن يكون أكبر من صفر.');
        }
        if (trim((string) ($data['description'] ?? '')) === '') {
            throw new BusinessException('يجب كتابة وصف للمصروف.');
        }

        return DB::transaction(function () use ($data, $amount, $user) {
            $category = ExpenseCategory::query()->where('is_active', true)->findOrFail($data['expense_category_id']);
            $method = PaymentMethod::query()->where('is_active', true)->findOrFail($data['payment_method_id']);
            $session = $method->isCash() ? $this->registers->requireOpenSession($user) : null;

            $expense = Expense::query()->create([
                'number' => $this->numbers->next('expense'),
                'expense_category_id' => $category->id,
                'amount' => $amount,
                'description' => $data['description'],
                'expense_date' => $data['expense_date'] ?? today(),
                'payment_method_id' => $method->id,
                'cash_register_session_id' => $session?->id,
                'reference' => $data['reference'] ?? null,
                'user_id' => $user->id,
            ]);

            if ($session) {
                $this->registers->record($session, CashMovementType::Expense, CashDirection::Out, $amount, $user, $expense,
                    "مصروف {$expense->number}: {$category->name}");
            }

            $this->audit->log('expense_created', $expense, null, [
                'number' => $expense->number, 'amount' => $amount, 'category' => $category->name,
            ], userId: $user->id);

            return $expense;
        });
    }

    public function void(Expense $expense, string $reason, User $user): Expense
    {
        if (! $user->can('expenses.void')) {
            throw new AuthorizationException('ليس لديك صلاحية إلغاء المصروفات.');
        }
        if (trim($reason) === '') {
            throw new BusinessException('يجب ذكر سبب الإلغاء.');
        }

        return DB::transaction(function () use ($expense, $reason, $user) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($expense->isVoided()) {
                throw new BusinessException('هذا المصروف ملغى مسبقًا.');
            }

            if ($expense->cash_register_session_id) {
                // Money comes back into the drawer that is open now (the
                // original session may already be closed and counted).
                $session = $expense->session->isOpen() ? $expense->session : $this->registers->requireOpenSession($user);
                $this->registers->record($session, CashMovementType::ExpenseVoid, CashDirection::In, $expense->amount, $user, $expense,
                    "إلغاء مصروف {$expense->number}");
            }

            $expense->update([
                'status' => RecordStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $user->id,
                'void_reason' => $reason,
            ]);

            $this->audit->log('expense_voided', $expense, ['status' => 'active'], ['status' => 'voided'], $reason, userId: $user->id);

            return $expense;
        });
    }
}
