<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'draft',
        'subtotal' => '0', 'tax_amount' => '0', 'total' => '0',
        'received_total' => '0', 'returned_total' => '0', 'paid_amount' => '0',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'order_date' => 'date',
            'expected_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'received_total' => 'decimal:2',
            'returned_total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /** Amount still owed on what was actually received. */
    public function amountDue(): string
    {
        return Money::sub(Money::sub($this->received_total, $this->returned_total), $this->paid_amount);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [PurchaseStatus::Draft, PurchaseStatus::Ordered], true)
            && Money::isZero($this->received_total);
    }

    public function canReceive(): bool
    {
        return in_array($this->status, [PurchaseStatus::Draft, PurchaseStatus::Ordered, PurchaseStatus::PartiallyReceived], true);
    }
}
