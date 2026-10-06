<?php

namespace App\Models;

use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    /** balance is not fillable: it only changes through SupplierAccountService. */
    protected $fillable = ['name', 'phone', 'email', 'address', 'tax_number', 'notes', 'is_active'];

    protected $attributes = ['balance' => '0', 'is_active' => true];

    protected function casts(): array
    {
        return ['balance' => 'decimal:2', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(fn (Supplier $s) => $s->name_normalized = ArabicText::normalize($s->name));
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SupplierLedgerEntry::class);
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
}
