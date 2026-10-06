<?php

namespace App\Models;

use App\Enums\PaymentMethodType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'type', 'is_active', 'sort_order'];

    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['type' => PaymentMethodType::class, 'is_active' => 'boolean'];
    }

    public function isCash(): bool
    {
        return $this->type === PaymentMethodType::Cash;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public static function cash(): self
    {
        return static::query()->where('code', 'cash')->firstOrFail();
    }
}
