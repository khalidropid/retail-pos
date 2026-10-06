<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ProductService
{
    private const PRICE_FIELDS = ['sale_price', 'cost_price', 'tax_rate'];

    public function __construct(
        private AuditLogger $audit,
        private InventoryService $inventory,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor, string|int|float|null $openingStock = null): Product
    {
        if (! $actor->can('products.create')) {
            throw new AuthorizationException('ليس لديك صلاحية إضافة منتج.');
        }

        return DB::transaction(function () use ($data, $actor, $openingStock) {
            $product = Product::query()->create($data);

            $this->audit->log('created', $product, null, $product->only([
                'name', 'sku', 'barcode', 'sale_price', 'cost_price', 'tax_rate',
            ]), userId: $actor->id);

            if ($openingStock !== null && Money::isPositive($openingStock)) {
                $this->inventory->recordOpeningStock($product, (string) $openingStock, $actor);
            }

            return $product->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data, User $actor): Product
    {
        if (! $actor->can('products.update')) {
            throw new AuthorizationException('ليس لديك صلاحية تعديل المنتجات.');
        }

        unset($data['stock_quantity']);

        return DB::transaction(function () use ($product, $data, $actor) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $product->fill($data);

            $priceChanges = array_intersect(self::PRICE_FIELDS, array_keys($product->getDirty()));
            if ($priceChanges !== [] && ! $actor->can('products.update_price')) {
                throw new AuthorizationException('ليس لديك صلاحية تعديل أسعار المنتجات.');
            }

            if (isset($data['sale_price']) && Money::isNegative($data['sale_price'])) {
                throw new BusinessException('سعر البيع لا يمكن أن يكون سالبًا.');
            }

            $oldPrices = collect($priceChanges)->mapWithKeys(fn ($f) => [$f => $product->getOriginal($f)])->all();
            $product->save();

            if ($priceChanges !== []) {
                $this->audit->log('price_changed', $product, $oldPrices,
                    collect($priceChanges)->mapWithKeys(fn ($f) => [$f => $product->{$f}])->all(), userId: $actor->id);
            }

            $other = array_diff(array_keys($product->getChanges()), [...self::PRICE_FIELDS, 'updated_at', 'name_normalized']);
            if ($other !== []) {
                $this->audit->log('updated', $product,
                    collect($other)->mapWithKeys(fn ($f) => [$f => $product->getOriginal($f)])->all(),
                    collect($other)->mapWithKeys(fn ($f) => [$f => $product->{$f}])->all(), userId: $actor->id);
            }

            return $product;
        });
    }

    public function delete(Product $product, User $actor): void
    {
        if (! $actor->can('products.delete')) {
            throw new AuthorizationException('ليس لديك صلاحية حذف المنتجات.');
        }

        if ($product->track_stock && ! Money::isZero($product->stock_quantity)) {
            throw new BusinessException('لا يمكن حذف منتج له رصيد مخزون. قم بتسوية المخزون إلى صفر أو عطّل المنتج بدلًا من ذلك.');
        }

        $product->delete();
        $this->audit->log('deleted', $product, $product->only(['name', 'sku', 'barcode']), userId: $actor->id);
    }
}
