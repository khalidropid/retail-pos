<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Enums\SaleType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'completed',
        'discount_amount' => '0', 'tax_amount' => '0', 'paid_amount' => '0',
        'returned_amount' => '0', 'refunded_amount' => '0', 'balance_due' => '0',
        'credit_limit_overridden' => false,
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'datetime',
            'due_date' => 'date',
            'cancelled_at' => 'datetime',
            'sale_type' => SaleType::class,
            'status' => SaleStatus::class,
            'payment_status' => PaymentStatus::class,
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'returned_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'amount_tendered' => 'decimal:2',
            'change_given' => 'decimal:2',
            'credit_limit_overridden' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Sales cannot be deleted; cancel them instead.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    /** Net receivable on this invoice after returns. */
    public function netTotal(): string
    {
        return Money::sub($this->total, $this->returned_amount);
    }

    /** Recompute the derived fields from the stored amounts. */
    public function recalculate(): void
    {
        $this->balance_due = Money::add(Money::sub(Money::sub($this->total, $this->returned_amount), $this->paid_amount), $this->refunded_amount);

        $netPaid = Money::sub($this->paid_amount, $this->refunded_amount);
        $this->payment_status = match (true) {
            Money::isZero($this->balance_due) => PaymentStatus::Paid,
            Money::isPositive($netPaid) => PaymentStatus::PartiallyPaid,
            default => PaymentStatus::Unpaid,
        };
    }

    public function isCancelled(): bool
    {
        return $this->status === SaleStatus::Cancelled;
    }

    public function isOverdue(): bool
    {
        return ! $this->isCancelled()
            && Money::isPositive($this->balance_due)
            && $this->due_date !== null
            && $this->due_date->lt(today());
    }

    /** Payment status as shown to users (adds "overdue"). */
    public function displayPaymentStatus(): PaymentStatus
    {
        return $this->isOverdue() ? PaymentStatus::Overdue : $this->payment_status;
    }

    public function daysOverdue(): int
    {
        return $this->isOverdue() ? (int) $this->due_date->diffInDays(today()) : 0;
    }

    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->where('status', '!=', SaleStatus::Cancelled->value);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->notCancelled()->where('balance_due', '>', 0);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->outstanding()->whereNotNull('due_date')->whereDate('due_date', '<', today());
    }
}
