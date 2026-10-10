<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Electronic wallet / account type used with the "Accounts" payment method. */
class PaymentAccountType extends Model
{
    protected $fillable = ['name', 'code', 'provider', 'is_active', 'requires_reference', 'available_at_pos', 'sort_order', 'notes'];

    protected $attributes = ['is_active' => true, 'requires_reference' => true, 'available_at_pos' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'requires_reference' => 'boolean', 'available_at_pos' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
