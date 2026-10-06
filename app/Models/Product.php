<?php

namespace App\Models;

use App\Support\ArabicText;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * stock_quantity is deliberately NOT fillable: on-hand quantity only
     * changes through InventoryService together with a stock movement.
     */
    protected $fillable = [
        'name', 'sku', 'barcode', 'category_id', 'brand_id', 'unit_id', 'description',
        'image_path', 'cost_price', 'sale_price', 'tax_rate', 'reorder_level', 'min_stock',
        'track_stock', 'is_active',
    ];

    protected $attributes = [
        'cost_price' => '0',
        'sale_price' => '0',
        'tax_rate' => '0',
        'stock_quantity' => '0',
        'reorder_level' => '0',
        'min_stock' => '0',
        'track_stock' => true,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:4',
            'sale_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'stock_quantity' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            $product->name_normalized = ArabicText::normalize($product->name);
            $product->sku = trim((string) $product->sku);
            $product->barcode = filled($product->barcode) ? trim((string) $product->barcode) : null;
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** At or below the reorder level (only for stock-tracked products). */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->where('track_stock', true)
            ->whereColumn('stock_quantity', '<=', 'reorder_level');
    }

    /**
     * Fast search: exact barcode/SKU first, then normalized-name substring
     * (served by the trigram index).
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $normalized = ArabicText::likeEscape(ArabicText::normalize($term));
        $raw = ArabicText::likeEscape($term);

        return $query->where(function (Builder $q) use ($term, $normalized, $raw) {
            $q->where('barcode', $term)
                ->orWhereRaw('lower(sku) = lower(?)', [$term])
                ->orWhere('name_normalized', 'like', "%{$normalized}%")
                ->orWhere('sku', 'ilike', "%{$raw}%");
        });
    }

    public function isLowStock(): bool
    {
        return $this->track_stock && Money::lte($this->stock_quantity, $this->reorder_level);
    }

    public function stockValue(): string
    {
        return Money::mul($this->stock_quantity, $this->cost_price);
    }
}
