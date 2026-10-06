<?php

namespace App\Services;

use App\Enums\CashDirection;
use App\Enums\CashMovementType;
use App\Enums\RegisterSessionStatus;
use App\Exceptions\BusinessException;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CashRegisterService
{
    public function __construct(
        private NumberingService $numbers,
        private AuditLogger $audit,
    ) {}

    public function openRegister(CashRegister $register, string|int|float $openingBalance, User $user, ?string $notes = null): CashRegisterSession
    {
        if (! $user->can('cash_register.open')) {
            throw new AuthorizationException('ليس لديك صلاحية فتح الصندوق.');
        }

        $openingBalance = Money::round($openingBalance);
        if (Money::isNegative($openingBalance)) {
            throw new BusinessException('الرصيد الافتتاحي لا يمكن أن يكون سالبًا.');
        }
        if (! $register->is_active) {
            throw new BusinessException('هذا الصندوق غير نشط.');
        }

        try {
            return DB::transaction(function () use ($register, $openingBalance, $user, $notes) {
                if (CashRegisterSession::query()->open()->where('user_id', $user->id)->exists()) {
                    throw new BusinessException('لديك صندوق مفتوح بالفعل. أغلقه قبل فتح صندوق جديد.');
                }
                if (CashRegisterSession::query()->open()->where('cash_register_id', $register->id)->exists()) {
                    throw new BusinessException('هذا الصندوق مفتوح حاليًا من قبل مستخدم آخر.');
                }

                $session = CashRegisterSession::query()->create([
                    'number' => $this->numbers->next('cash_register_session'),
                    'cash_register_id' => $register->id,
                    'user_id' => $user->id,
                    'status' => RegisterSessionStatus::Open,
                    'opened_at' => now(),
                    'opening_balance' => $openingBalance,
                    'opening_notes' => $notes,
                ]);

                $this->audit->log('register_opened', $session, null, [
                    'register' => $register->name, 'opening_balance' => $openingBalance,
                ], userId: $user->id);

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent open (partial unique index).
            throw new BusinessException('تم فتح هذا الصندوق أو صندوق لك في نفس اللحظة. حدّث الصفحة.');
        }
    }

    public function currentSession(User $user): ?CashRegisterSession
    {
        return CashRegisterSession::query()->open()->where('user_id', $user->id)->with('register')->first();
    }

    public function requireOpenSession(User $user): CashRegisterSession
    {
        return $this->currentSession($user)
            ?? throw new BusinessException('يجب فتح صندوق قبل تنفيذ عملية نقدية.');
    }

    /**
     * Record cash entering or leaving the drawer. Locks the session row so
     * the "enough cash" check and the insert are atomic.
     */
    public function record(
        CashRegisterSession $session,
        CashMovementType $type,
        CashDirection $direction,
        string|int|float $amount,
        User $user,
        ?Model $reference = null,
        ?string $notes = null,
    ): CashMovement {
        $amount = Money::round($amount);
        if (! Money::isPositive($amount)) {
            throw new BusinessException('مبلغ الحركة النقدية يجب أن يكون أكبر من صفر.');
        }

        return DB::transaction(function () use ($session, $type, $direction, $amount, $user, $reference, $notes) {
            $locked = CashRegisterSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! $locked->isOpen()) {
                throw new BusinessException('جلسة الصندوق مغلقة.');
            }

            if ($direction === CashDirection::Out) {
                $available = $this->expectedCash($locked);
                if (Money::gt($amount, $available)) {
                    throw new BusinessException('النقد المتوفر في الصندوق ('.money($available).') غير كافٍ لصرف '.money($amount).'.');
                }
            }

            return CashMovement::query()->create([
                'cash_register_session_id' => $locked->id,
                'type' => $type,
                'direction' => $direction,
                'amount' => $amount,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'user_id' => $user->id,
                'notes' => $notes,
            ]);
        });
    }

    public function cashIn(CashRegisterSession $session, string|int|float $amount, string $reason, User $user): CashMovement
    {
        return $this->manual($session, CashMovementType::CashIn, CashDirection::In, $amount, $reason, $user);
    }

    public function cashOut(CashRegisterSession $session, string|int|float $amount, string $reason, User $user): CashMovement
    {
        return $this->manual($session, CashMovementType::CashOut, CashDirection::Out, $amount, $reason, $user);
    }

    private function manual(CashRegisterSession $session, CashMovementType $type, CashDirection $direction, string|int|float $amount, string $reason, User $user): CashMovement
    {
        if (! $user->can('cash_register.cash_in_out')) {
            throw new AuthorizationException('ليس لديك صلاحية الإيداع والسحب النقدي.');
        }
        if (trim($reason) === '') {
            throw new BusinessException('يجب ذكر سبب الحركة النقدية.');
        }
        $this->assertCanOperate($session, $user);

        $movement = $this->record($session, $type, $direction, $amount, $user, null, $reason);
        $this->audit->log($type === CashMovementType::CashIn ? 'cash_in' : 'cash_out', $session, null,
            ['amount' => $movement->amount], $reason, userId: $user->id);

        return $movement;
    }

    public function expectedCash(CashRegisterSession $session): string
    {
        $row = DB::table('cash_movements')->where('cash_register_session_id', $session->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) AS cash_in")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) AS cash_out")
            ->first();

        return Money::sub(Money::add($session->opening_balance, $row->cash_in), $row->cash_out);
    }

    /**
     * Totals per movement type (for the session report / Z report).
     *
     * @return array<string, array{in:string, out:string}>
     */
    public function summary(CashRegisterSession $session): array
    {
        return DB::table('cash_movements')->where('cash_register_session_id', $session->id)
            ->groupBy('type', 'direction')
            ->selectRaw('type, direction, SUM(amount) AS total')
            ->get()
            ->groupBy('type')
            ->map(fn ($rows) => [
                'in' => Money::round($rows->firstWhere('direction', 'in')->total ?? 0),
                'out' => Money::round($rows->firstWhere('direction', 'out')->total ?? 0),
            ])->all();
    }

    public function closeRegister(CashRegisterSession $session, string|int|float $actualCash, User $user, ?string $notes = null): CashRegisterSession
    {
        if (! $user->can('cash_register.close')) {
            throw new AuthorizationException('ليس لديك صلاحية إغلاق الصندوق.');
        }

        $actualCash = Money::round($actualCash);
        if (Money::isNegative($actualCash)) {
            throw new BusinessException('النقد الفعلي لا يمكن أن يكون سالبًا.');
        }

        return DB::transaction(function () use ($session, $actualCash, $user, $notes) {
            $locked = CashRegisterSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! $locked->isOpen()) {
                throw new BusinessException('هذا الصندوق مغلق مسبقًا.');
            }
            $this->assertCanOperate($locked, $user);

            $expected = $this->expectedCash($locked);
            $difference = Money::sub($actualCash, $expected);

            $locked->update([
                'status' => RegisterSessionStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $user->id,
                'expected_cash' => $expected,
                'actual_cash' => $actualCash,
                'difference' => $difference,
                'closing_notes' => $notes,
            ]);

            $this->audit->log('register_closed', $locked, null, [
                'expected_cash' => $expected, 'actual_cash' => $actualCash, 'difference' => $difference,
            ], $notes, userId: $user->id);

            return $locked;
        });
    }

    private function assertCanOperate(CashRegisterSession $session, User $user): void
    {
        if ($session->user_id !== $user->id && ! $user->can('cash_register.view_all')) {
            throw new AuthorizationException('لا يمكنك التعامل مع صندوق مستخدم آخر.');
        }
    }
}
