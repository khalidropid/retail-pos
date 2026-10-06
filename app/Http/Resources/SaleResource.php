<?php

namespace App\Http\Resources;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Sale */
class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'sale_date' => $this->sale_date->toIso8601String(),
            'sale_type' => $this->sale_type->value,
            'status' => $this->status->value,
            'payment_status' => $this->displayPaymentStatus()->value,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'name' => $this->customer->name] : null),
            'cashier' => $this->whenLoaded('user', fn () => $this->user->name),
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'total' => $this->total,
            'paid_amount' => $this->paid_amount,
            'returned_amount' => $this->returned_amount,
            'balance_due' => $this->balance_due,
            'due_date' => $this->due_date?->toDateString(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'name' => $i->product_name,
                'quantity' => $i->quantity,
                'returned_quantity' => $i->returned_quantity,
                'unit_price' => $i->unit_price,
                'discount_amount' => $i->discount_amount,
                'tax_amount' => $i->tax_amount,
                'line_total' => $i->line_total,
            ])),
            'receipt_url' => route('print.sale-receipt', $this->resource),
        ];
    }
}
