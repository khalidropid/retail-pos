<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'category' => $this->whenLoaded('category', fn () => $this->category?->name),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit->short_name),
            'sale_price' => $this->sale_price,
            'cost_price' => $this->when($request->user()?->can('products.view_cost'), $this->cost_price),
            'tax_rate' => $this->tax_rate,
            'stock_quantity' => $this->track_stock ? $this->stock_quantity : null,
            'is_low_stock' => $this->isLowStock(),
            'is_active' => $this->is_active,
        ];
    }
}
