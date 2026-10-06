<?php

namespace Tests\Feature\Catalog;

use App\Exceptions\BusinessException;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Unit;
use App\Services\ProductService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Livewire\Livewire;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use BuildsDomainData;

    public function test_product_is_created_with_opening_stock_movement(): void
    {
        $admin = $this->userWithRole('admin');
        $unit = Unit::factory()->create();

        $product = app(ProductService::class)->create([
            'name' => 'حليب طازج', 'sku' => 'MLK-1', 'barcode' => '111', 'unit_id' => $unit->id,
            'cost_price' => '1000', 'sale_price' => '1500',
        ], $admin, '25');

        $this->assertSame('25.000', $product->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id, 'type' => 'opening', 'quantity' => '25.000',
            'quantity_before' => '0.000', 'quantity_after' => '25.000', 'user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'created', 'auditable_id' => $product->id]);
    }

    public function test_duplicate_barcode_and_sku_are_rejected_by_database(): void
    {
        Product::factory()->create(['barcode' => '999', 'sku' => 'DUP']);

        $this->expectException(QueryException::class);
        Product::factory()->create(['barcode' => '999']);
    }

    public function test_duplicate_sku_is_case_insensitive(): void
    {
        Product::factory()->create(['sku' => 'abc-1']);

        $this->expectException(QueryException::class);
        Product::factory()->create(['sku' => 'ABC-1']);
    }

    public function test_barcode_of_deleted_product_can_be_reused(): void
    {
        $old = Product::factory()->create(['barcode' => '555']);
        $old->delete();

        $new = Product::factory()->create(['barcode' => '555']);
        $this->assertNotNull($new->id);
    }

    public function test_price_change_requires_permission_and_is_audited(): void
    {
        $product = Product::factory()->create(['sale_price' => '1000']);
        $clerk = $this->userWithRole('inventory_clerk');

        try {
            app(ProductService::class)->update($product, ['sale_price' => '1200'], $clerk);
            $this->fail('Clerk should not change prices');
        } catch (AuthorizationException) {
        }
        $this->assertSame('1000.00', $product->refresh()->sale_price);

        $manager = $this->userWithRole('manager');
        app(ProductService::class)->update($product, ['sale_price' => '1200'], $manager);

        $log = AuditLog::query()->where('event', 'price_changed')->latest('id')->firstOrFail();
        $this->assertEquals('1000.00', $log->old_values['sale_price']);
        $this->assertEquals('1200.00', $log->new_values['sale_price']);
    }

    public function test_update_cannot_change_stock_directly(): void
    {
        $product = $this->stocked(10);
        app(ProductService::class)->update($product, ['stock_quantity' => '999', 'name' => 'اسم جديد'], $this->userWithRole('admin'));

        $this->assertSame('10.000', $product->refresh()->stock_quantity);
    }

    public function test_product_with_stock_cannot_be_deleted(): void
    {
        $product = $this->stocked(5);

        $this->expectException(BusinessException::class);
        app(ProductService::class)->delete($product, $this->userWithRole('admin'));
    }

    public function test_search_by_barcode_sku_and_arabic_variants(): void
    {
        $p = Product::factory()->create(['name' => 'طحينة الأصيل', 'sku' => 'THN-9', 'barcode' => '628000']);

        $this->assertTrue(Product::search('628000')->whereKey($p->id)->exists());
        $this->assertTrue(Product::search('thn-9')->whereKey($p->id)->exists());
        $this->assertTrue(Product::search('طحينه الاصيل')->whereKey($p->id)->exists());
        $this->assertFalse(Product::search('%')->whereKey($p->id)->exists());
    }

    public function test_low_stock_scope(): void
    {
        $low = $this->stocked(3, ['reorder_level' => 5]);
        $ok = $this->stocked(50, ['reorder_level' => 5]);

        $ids = Product::lowStock()->pluck('id');
        $this->assertTrue($ids->contains($low->id));
        $this->assertFalse($ids->contains($ok->id));
    }

    public function test_cashier_cannot_create_products_via_panel(): void
    {
        $this->actingAs($this->userWithRole('cashier'))->get('/admin/products/create')->assertForbidden();
    }

    public function test_product_created_through_filament_form(): void
    {
        $unit = Unit::factory()->create();
        Livewire::actingAs($this->userWithRole('admin'))->test(CreateProduct::class)
            ->fillForm([
                'name' => 'منتج من الواجهة', 'sku' => 'UI-1', 'barcode' => '7770001', 'unit_id' => $unit->id,
                'cost_price' => 500, 'sale_price' => 800, 'tax_rate' => 0, 'opening_stock' => 12,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('12.000', Product::query()->where('sku', 'UI-1')->value('stock_quantity'));
    }
}
