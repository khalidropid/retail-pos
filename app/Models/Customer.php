<?php

namespace App\Models;

use App\Support\ArabicText;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    /** balance is not fillable: it only changes through CustomerAccountService. */
    protected $fillable = ['name', 'phone', 'email', 'address', 'tax_number', 'credit_limit', 'credit_days', 'notes', 'is_active'];

    protected $attributes = ['balance' => '0', 'credit_limit' => '0', 'is_active' => true];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'balance' => 'decimal:2',
            'credit_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Customer $c) {
            $c->name_normalized = ArabicText::normalize($c->name);
            $c->phone = filled($c->phone) ? ArabicText::normalize(preg_replace('/\s+/', '', (string) $c->phone)) : null;
        });
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CustomerLedgerEntry::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }
        $n = ArabicText::likeEscape(ArabicText::normalize($term));

        return $query->where(fn ($q) => $q->where('name_normalized', 'like', "%{$n}%")->orWhere('phone', 'like', "%{$n}%"));
    }

    public function effectiveCreditDays(): int
    {
        return $this->credit_days ?? (int) settings('credit.default_days', 30);
    }

    /** Credit still available (never below zero). */
    public function availableCredit(): string
    {
        return Money::max(Money::sub($this->credit_limit, $this->balance), '0');
    }
}
