<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $debts = $request->user()?->can('customers.view_debts');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'tax_number' => $this->tax_number,
            'credit_days' => $this->effectiveCreditDays(),
            'balance' => $this->when($debts, $this->balance),
            'credit_limit' => $this->when($debts, $this->credit_limit),
            'available_credit' => $this->when($debts, fn () => $this->availableCredit()),
            'is_active' => $this->is_active,
        ];
    }
}
