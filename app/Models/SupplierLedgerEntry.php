<?php

namespace App\Models;

use App\Enums\SupplierLedgerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class SupplierLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => SupplierLedgerType::class,
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'entry_date' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger entries are immutable.'));
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
