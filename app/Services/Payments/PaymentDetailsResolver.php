<?php

namespace App\Services\Payments;

use App\Exceptions\BusinessException;
use App\Models\PaymentAccountType;
use App\Models\PaymentMethod;

/**
 * Single place for payment-method rules (account type, reference). Every
 * screen and the API go through here, so the rules are configured on the
 * payment method / account type records instead of hard-coded in the UI.
 */
class PaymentDetailsResolver
{
    public const REFERENCE_MIN = 3;

    public const REFERENCE_MAX = 100;

    public function resolve(int|PaymentMethod|null $method, int|string|null $accountTypeId = null, ?string $reference = null, bool $atPos = false): PaymentDetails
    {
        $method = $method instanceof PaymentMethod ? $method : PaymentMethod::query()->find($method);
        if (! $method || ! $method->is_active) {
            throw new BusinessException('طريقة الدفع غير موجودة أو غير مفعّلة.');
        }
        if ($atPos && ! $method->available_at_pos) {
            throw new BusinessException("طريقة الدفع \"{$method->name}\" غير متاحة في نقطة البيع.");
        }

        $accountType = null;
        if ($method->requires_account_type) {
            if (blank($accountTypeId)) {
                throw new BusinessException("يجب اختيار نوع الحساب عند الدفع بـ \"{$method->name}\".");
            }
            $accountType = PaymentAccountType::query()->find($accountTypeId);
            if (! $accountType || ! $accountType->is_active || ($atPos && ! $accountType->available_at_pos)) {
                throw new BusinessException('نوع الحساب المختار غير متاح.');
            }
        }

        $reference = $this->normalizeReference($reference);
        $needsReference = $method->requires_reference || ($accountType?->requires_reference ?? false);

        if ($needsReference && $reference === null) {
            throw new BusinessException('يجب إدخال رقم المرجع لعملية "'.$method->name.($accountType ? ' - '.$accountType->name : '').'".');
        }

        return new PaymentDetails($method, $accountType, $reference);
    }

    private function normalizeReference(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        // Whitespace (incl. newlines/tabs) becomes single spaces first, then any
        // remaining invisible/control characters are stripped.
        $reference = preg_replace('/\s+/u', ' ', $reference) ?? '';
        $reference = trim(preg_replace('/[\p{Cc}\p{Cf}]/u', '', $reference) ?? '');
        if ($reference === '') {
            return null;
        }

        $length = mb_strlen($reference);
        if ($length < self::REFERENCE_MIN || $length > self::REFERENCE_MAX) {
            throw new BusinessException('رقم المرجع يجب أن يكون بين '.self::REFERENCE_MIN.' و'.self::REFERENCE_MAX.' حرفًا.');
        }

        return $reference;
    }
}
