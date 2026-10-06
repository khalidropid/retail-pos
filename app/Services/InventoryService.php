<?php

namespace App\Services;

use App\Enums\StockCountStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only code path allowed to change products.stock_quantity.
 *
 * Each change locks the product row (SELECT ... FOR UPDATE), validates the
 * resulting quantity and writes an immutable stock_movements row with the
 * before/after balance, all inside one transaction.
 */
class InventoryService
{
    public function __construct(
        private NumberingService $numbers,
        private AuditLogger $audit,
    ) {}

    /**
     * Lock several products in ascending id order (prevents deadlocks when two
     * sales touch the same products in different orders).
     *
     * @param  array<int, int>  $productIds
     * @return Collection<int, Product>
     */
    public function lockProducts(array $productIds): Collection
    {
        $ids = collect($productIds)->unique()->sort()->values()->all();

        return Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /**
     * Apply a signed quantity change to a product that the caller has already
     * locked (or that will be locked here).
     */
    public function move(
        Product $product,
        string|int|float $quantity,
        StockMovementType $type,
        User $user,
        ?Model $reference = null,
        ?string $notes = null,
        string|int|float|null $unitCost = null,
    ): ?StockMovement {
        $quantity = Money::qty($quantity);

        if (Money::isZero($quantity)) {
            return null;
        }

        return DB::transaction(function () use ($product, $quantity, $type, $user, $reference, $notes, $unitCost) {
            /** @var Product $locked */
            $locked = Product::query()->withTrashed()->lockForUpdate()->findOrFail($product->id);

            if (! $locked->track_stock) {
                return null;
            }

            $before = Money::qty($locked->stock_quantity);
            $after = Money::qty(Money::of($before)->plus(Money::of($quantity)));

            if (Money::isNegative($after) && Money::isNegative($quantity) && ! settings('inventory.allow_negative_stock', false)) {
                throw new BusinessException(sprintf(
                    'الكمية المتوفرة من "%s" غير كافية. المتوفر: %s، المطلوب: %s.',
                    $locked->name,
                    Money::formatQty($before),
                    Money::formatQty(Money::of($quantity)->abs()),
                ));
            }

            DB::table('products')->where('id', $locked->id)->update([
                'stock_quantity' => $after,
                'updated_at' => now(),
            ]);

            $product->setRawAttributes(array_merge($product->getAttributes(), ['stock_quantity' => $after]), true);

            return StockMovement::query()->create([
                'product_id' => $locked->id,
                'type' => $type,
                'quantity' => $quantity,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'unit_cost' => Money::round($unitCost ?? $locked->cost_price, Money::COST_SCALE),
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'user_id' => $user->id,
                'notes' => $notes,
            ]);
        });
    }

    public function recordOpeningStock(Product $product, string $quantity, User $user): ?StockMovement
    {
        if (Money::isNegative($quantity)) {
            throw new BusinessException('رصيد الافتتاح لا يمكن أن يكون سالبًا.');
        }

        return $this->move($product, $quantity, StockMovementType::Opening, $user, $product, 'رصيد افتتاحي');
    }

    /**
     * Manual adjustment of one or more products.
     *
     * @param  array<int, array{product_id:int, quantity:string|int|float, notes?:string|null}>  $items  signed quantities
     */
    public function adjustStock(array $items, string $reason, User $user, ?string $notes = null): StockAdjustment
    {
        if (! $user->can('inventory.adjust')) {
            throw new AuthorizationException('ليس لديك صلاحية تعديل المخزون.');
        }

        if (trim($reason) === '') {
            throw new BusinessException('يجب ذكر سبب التسوية.');
        }

        $items = array_values(array_filter($items, fn ($i) => ! Money::isZero($i['quantity'] ?? 0)));
        if ($items === []) {
            throw new BusinessException('يجب إدخال صنف واحد على الأقل بكمية غير صفرية.');
        }

        return DB::transaction(function () use ($items, $reason, $user, $notes) {
            $products = $this->lockProducts(array_column($items, 'product_id'));

            $adjustment = StockAdjustment::query()->create([
                'number' => $this->numbers->next('stock_adjustment'),
                'user_id' => $user->id,
                'reason' => $reason,
                'notes' => $notes,
                'adjusted_at' => now(),
            ]);

            foreach ($items as $item) {
                $product = $products->get($item['product_id']) ?? throw new BusinessException('المنتج غير موجود.');

                if (! $product->track_stock) {
                    throw new BusinessException("المنتج \"{$product->name}\" لا يتتبع المخزون.");
                }

                $qty = Money::qty($item['quantity']);
                $type = Money::isPositive($qty) ? StockMovementType::AdjustmentIn : StockMovementType::AdjustmentOut;
                $movement = $this->move($product, $qty, $type, $user, $adjustment, $item['notes'] ?? $reason);

                $adjustment->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'quantity_before' => $movement->quantity_before,
                    'quantity_after' => $movement->quantity_after,
                    'unit_cost' => $product->cost_price,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            $this->audit->log('stock_adjusted', $adjustment, null, [
                'number' => $adjustment->number,
                'items' => $adjustment->items()->get(['product_id', 'quantity', 'quantity_before', 'quantity_after'])->toArray(),
            ], $reason, userId: $user->id);

            return $adjustment->load('items');
        });
    }

    /**
     * @param  array<int, array{product_id:int, counted_quantity:string|int|float}>  $items
     */
    public function createCount(array $items, User $user, ?string $notes = null): StockCount
    {
        if (! $user->can('inventory.count')) {
            throw new AuthorizationException('ليس لديك صلاحية الجرد.');
        }

        if ($items === []) {
            throw new BusinessException('يجب إدخال صنف واحد على الأقل في الجرد.');
        }

        return DB::transaction(function () use ($items, $user, $notes) {
            $count = StockCount::query()->create([
                'number' => $this->numbers->next('stock_count'),
                'user_id' => $user->id,
                'notes' => $notes,
            ]);

            foreach ($items as $item) {
                if (Money::isNegative($item['counted_quantity'])) {
                    throw new BusinessException('الكمية المعدودة لا يمكن أن تكون سالبة.');
                }
                $product = Product::query()->findOrFail($item['product_id']);
                $count->items()->create([
                    'product_id' => $product->id,
                    'expected_quantity' => $product->stock_quantity,
                    'counted_quantity' => Money::qty($item['counted_quantity']),
                    'difference' => Money::qty(Money::of($item['counted_quantity'])->minus(Money::of($product->stock_quantity))),
                ]);
            }

            return $count->load('items');
        });
    }

    /**
     * Approve a count: the difference is computed against the stock at
     * approval time (sales may have happened since the count was entered).
     */
    public function approveCount(StockCount $count, User $user): StockCount
    {
        if (! $user->can('inventory.count') || ! $user->can('inventory.adjust')) {
            throw new AuthorizationException('ليس لديك صلاحية اعتماد الجرد.');
        }

        return DB::transaction(function () use ($count, $user) {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);

            if ($count->status !== StockCountStatus::Draft) {
                throw new BusinessException('هذا الجرد معتمد أو ملغى مسبقًا.');
            }

            $items = $count->items()->get();
            $products = $this->lockProducts($items->pluck('product_id')->all());

            foreach ($items as $item) {
                $product = $products->get($item->product_id);
                $expected = Money::qty($product->stock_quantity);
                $difference = Money::qty(Money::of($item->counted_quantity)->minus(Money::of($expected)));

                $item->update(['expected_quantity' => $expected, 'difference' => $difference]);

                if (! Money::isZero($difference)) {
                    $this->move($product, $difference, StockMovementType::StockCount, $user, $count, 'فرق جرد '.$count->number);
                }
            }

            $count->update([
                'status' => StockCountStatus::Completed,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            $this->audit->log('stock_counted', $count, null, [
                'number' => $count->number,
                'differences' => $count->items()->where('difference', '<>', 0)->get(['product_id', 'expected_quantity', 'counted_quantity', 'difference'])->toArray(),
            ], userId: $user->id);

            return $count->refresh();
        });
    }

    public function cancelCount(StockCount $count, User $user): void
    {
        if (! $user->can('inventory.count')) {
            throw new AuthorizationException('ليس لديك صلاحية الجرد.');
        }

        DB::transaction(function () use ($count) {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            if ($count->status !== StockCountStatus::Draft) {
                throw new BusinessException('لا يمكن إلغاء جرد معتمد.');
            }
            $count->update(['status' => StockCountStatus::Cancelled]);
        });
    }

    /**
     * New unit cost after receiving stock, per the configured costing method.
     * Must be called BEFORE the receiving movement updates stock_quantity.
     */
    public function newUnitCost(Product $lockedProduct, string $incomingQty, string $incomingUnitCost): string
    {
        if (settings('inventory.costing_method', 'weighted_average') === 'last_cost') {
            return Money::round($incomingUnitCost, Money::COST_SCALE);
        }

        $currentQty = Money::of($lockedProduct->stock_quantity);
        if (! $currentQty->isPositive()) {
            return Money::round($incomingUnitCost, Money::COST_SCALE);
        }

        $totalQty = $currentQty->plus(Money::of($incomingQty));
        $totalValue = $currentQty->multipliedBy(Money::of($lockedProduct->cost_price))
            ->plus(Money::of($incomingQty)->multipliedBy(Money::of($incomingUnitCost)));

        return Money::div($totalValue, $totalQty, Money::COST_SCALE);
    }
}
