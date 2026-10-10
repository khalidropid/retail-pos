<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerPayment extends Model
{
    public const SOURCE_POS = 'pos';

    public const SOURCE_ACCOUNT = 'account';

    public const VERIFICATION_PENDING = 'pending';

    public const VERIFICATION_VERIFIED = 'verified';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'payment_date' => 'datetime',
            'voided_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Customer payments cannot be deleted; reverse them instead.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
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

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }

    public function accountType(): BelongsTo
    {
        return $this->belongsTo(PaymentAccountType::class, 'payment_account_type_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by')->withTrashed();
    }

    /** Method as it was named when the payment was taken, plus account type. */
    public function methodLabel(): string
    {
        $name = $this->payment_method_name ?? $this->paymentMethod?->name ?? '-';

        return $name.($this->account_type_name ? ' - '.$this->account_type_name : '');
    }

    public function verificationLabel(): ?string
    {
        return match ($this->verification_status) {
            self::VERIFICATION_PENDING => 'بانتظار التأكيد',
            self::VERIFICATION_VERIFIED => 'مؤكد',
            default => null,
        };
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function isVoided(): bool
    {
        return $this->status === RecordStatus::Voided;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RecordStatus::Active->value);
    }
}
