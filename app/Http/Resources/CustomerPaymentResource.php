<?php

namespace App\Http\Resources;

use App\Models\CustomerPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerPayment */
class CustomerPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'customer_id' => $this->customer_id,
            'amount' => $this->amount,
            'payment_method' => $this->payment_method_name ?? $this->whenLoaded('paymentMethod', fn () => $this->paymentMethod->name),
            'account_type' => $this->account_type_name,
            'reference' => $this->reference,
            'verification_status' => $this->verification_status,
            'payment_date' => $this->payment_date->toIso8601String(),
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            'status' => $this->status->value,
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'sale_id' => $a->sale_id, 'amount' => $a->amount,
            ])),
            'receipt_url' => route('print.payment-receipt', $this->resource),
        ];
    }
}
