<?php

namespace Tests\Feature\Inventory;

use App\Enums\StockCountStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\StockMovement;
use App\Services\InventoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use BuildsDomainData;

    public function test_adjustment_records_before_and_after_quantities(): void
    {
        $product = $this->stocked(10);
        $clerk = $this->userWithRole('inventory_clerk');

        $adjustment = app(InventoryService::class)->adjustStock([
            ['product_id' => $product->id, 'quantity' => '-3'],
        ], 'تالف', $clerk);

        $this->assertSame('7.000', $product->refresh()->stock_quantity);
        $movement = StockMovement::query()->where('reference_id', $adjustment->id)->firstOrFail();
        $this->assertSame(StockMovementType::AdjustmentOut, $movement->type);
        $this->assertSame('10.000', $movement->quantity_before);
        $this->assertSame('7.000', $movement->quantity_after);
        $this->assertSame($clerk->id, $movement->user_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'stock_adjusted', 'reason' => 'تالف']);
    }

    public function test_unauthorized_user_cannot_adjust_stock(): void
    {
        $product = $this->stocked(10);

        $this->expectException(AuthorizationException::class);
        app(InventoryService::class)->adjustStock([['product_id' => $product->id, 'quantity' => '5']], 'x', $this->userWithRole('cashier'));
    }

    public function test_negative_stock_is_blocked_by_default(): void
    {
        $product = $this->stocked(2);

        try {
            app(InventoryService::class)->adjustStock([['product_id' => $product->id, 'quantity' => '-5']], 'x', $this->userWithRole('admin'));
            $this->fail('Expected negative stock to be blocked');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('غير كافية', $e->getMessage());
        }

        $this->assertSame('2.000', $product->refresh()->stock_quantity);
        $this->assertSame(1, StockMovement::query()->where('product_id', $product->id)->count());
    }

    public function test_negative_stock_allowed_when_setting_enabled(): void
    {
        settings()->set('inventory.allow_negative_stock', true);
        $product = $this->stocked(2);

        app(InventoryService::class)->adjustStock([['product_id' => $product->id, 'quantity' => '-5']], 'x', $this->userWithRole('admin'));

        $this->assertSame('-3.000', $product->refresh()->stock_quantity);
    }

    public function test_stock_count_uses_quantity_at_approval_time(): void
    {
        $product = $this->stocked(20);
        $admin = $this->userWithRole('admin');
        $service = app(InventoryService::class);

        $count = $service->createCount([['product_id' => $product->id, 'counted_quantity' => '18']], $admin);
        // A sale-like movement happens between counting and approval.
        $service->adjustStock([['product_id' => $product->id, 'quantity' => '-1']], 'بيع', $admin);

        $count = $service->approveCount($count, $admin);

        $this->assertSame(StockCountStatus::Completed, $count->status);
        $this->assertSame('18.000', $product->refresh()->stock_quantity);
        $this->assertSame('-1.000', $count->items()->first()->difference);
    }

    public function test_count_cannot_be_approved_twice(): void
    {
        $product = $this->stocked(5);
        $admin = $this->userWithRole('admin');
        $service = app(InventoryService::class);
        $count = $service->createCount([['product_id' => $product->id, 'counted_quantity' => '4']], $admin);
        $service->approveCount($count, $admin);

        $this->expectException(BusinessException::class);
        $service->approveCount($count, $admin);
    }

    public function test_ledger_rows_are_immutable(): void
    {
        $product = $this->stocked(5);
        $movement = StockMovement::query()->where('product_id', $product->id)->firstOrFail();

        $this->expectException(LogicException::class);
        $movement->update(['quantity' => '100']);
    }

    public function test_database_rejects_inconsistent_ledger_row(): void
    {
        $product = $this->stocked(5);

        $this->expectException(QueryException::class);
        DB::table('stock_movements')->insert([
            'product_id' => $product->id, 'type' => 'adjustment_in', 'quantity' => 5,
            'quantity_before' => 5, 'quantity_after' => 99, 'unit_cost' => 0,
        ]);
    }

    public function test_weighted_average_cost(): void
    {
        $product = $this->stocked(10, ['cost_price' => '100']);

        $cost = app(InventoryService::class)->newUnitCost($product, '10', '200');

        $this->assertSame('150.0000', $cost);
    }

    public function test_stock_balance_equals_sum_of_movements(): void
    {
        $product = $this->stocked(10);
        $admin = $this->userWithRole('admin');
        app(InventoryService::class)->adjustStock([['product_id' => $product->id, 'quantity' => '4.5']], 'x', $admin);
        app(InventoryService::class)->adjustStock([['product_id' => $product->id, 'quantity' => '-2']], 'x', $admin);

        $sum = StockMovement::query()->where('product_id', $product->id)->sum('quantity');
        $this->assertEquals('12.500', $product->refresh()->stock_quantity);
        $this->assertEquals(12.5, (float) $sum);
    }
}
