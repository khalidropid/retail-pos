<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'received_quantity' => 'decimal:3',
            'returned_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function remainingToReceive(): string
    {
        return Money::qty(Money::of($this->quantity)->minus(Money::of($this->received_quantity)));
    }

    public function returnableQuantity(): string
    {
        return Money::qty(Money::of($this->received_quantity)->minus(Money::of($this->returned_quantity)));
    }
}
