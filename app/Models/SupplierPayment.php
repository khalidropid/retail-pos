<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
            'amount' => 'decimal:2',
            'payment_date' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Payments cannot be deleted; void them instead.'));
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by')->withTrashed();
    }

    public function isVoided(): bool
    {
        return $this->status === RecordStatus::Voided;
    }
}
