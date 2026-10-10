<?php

namespace App\Services\Payments;

use App\Models\PaymentAccountType;
use App\Models\PaymentMethod;

/** Validated payment method + account type + reference, ready to store. */
final class PaymentDetails
{
    public function __construct(
        public readonly PaymentMethod $method,
        public readonly ?PaymentAccountType $accountType,
        public readonly ?string $reference,
    ) {}

    public function isCash(): bool
    {
        return $this->method->isCash();
    }

    public function needsVerification(): bool
    {
        return $this->method->requires_verification;
    }

    /** Column values for payment-like records (snapshots included). */
    public function attributes(string $prefix = ''): array
    {
        return [
            $prefix.'payment_method_name' => $this->method->name,
            $prefix.'payment_account_type_id' => $this->accountType?->id,
            $prefix.'account_type_name' => $this->accountType?->name,
        ];
    }

    /** Short human label, e.g. "الحسابات - جوالي". */
    public function label(): string
    {
        return $this->method->name.($this->accountType ? ' - '.$this->accountType->name : '');
    }
}
