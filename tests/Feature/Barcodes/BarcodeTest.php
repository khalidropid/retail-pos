<?php

namespace Tests\Feature\Barcodes;

use App\Exceptions\BusinessException;
use App\Filament\Pages\Pos;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\Product;
use App\Models\Unit;
use App\Services\BarcodeService;
use App\Services\ProductService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Livewire\Livewire;
use Picqer\Barcode\Types\TypeCode128;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class BarcodeTest extends TestCase
{
    use BuildsDomainData;

    public function test_generates_valid_internal_barcode_for_product_without_one(): void
    {
        $product = Product::factory()->create(['barcode' => null]);

        $updated = app(BarcodeService::class)->assign($product, $this->userWithRole('inventory_clerk'));

        $this->assertMatchesRegularExpression('/^20\d{11}$/', $updated->barcode);
        $this->assertTrue(BarcodeService::isValidInternal($updated->barcode));
        $this->assertSame('internal', $updated->refresh()->barcode_source);
        $this->assertDatabaseHas('audit_logs', ['event' => 'barcode_generated', 'auditable_id' => $product->id]);
    }

    public function test_generated_values_are_unique_and_skip_taken_codes(): void
    {
        $service = app(BarcodeService::class);
        // Someone typed the next internal code by hand: generation must skip it.
        $next = '200000000001'.BarcodeService::checkDigit('200000000001');
        Product::factory()->create(['barcode' => $next]);

        $values = collect(range(1, 50))->map(fn () => $service->nextValue());

        $this->assertCount(50, $values->unique());
        $this->assertNotContains($next, $values->all());
    }

    public function test_existing_barcode_is_never_overwritten_automatically(): void
    {
        $product = Product::factory()->create(['barcode' => '6281007012345']);

        try {
            app(BarcodeService::class)->assign($product, $this->userWithRole('admin'));
            $this->fail('existing barcode overwritten');
        } catch (BusinessException) {
        }

        $this->assertSame('6281007012345', $product->refresh()->barcode);
        $this->assertSame('manufacturer', $product->barcode_source);
    }

    public function test_regenerate_requires_permission(): void
    {
        $product = Product::factory()->create(['barcode' => '6281007012345']);

        try {
            app(BarcodeService::class)->assign($product, $this->userWithRole('inventory_clerk'), regenerate: true);
            $this->fail('clerk regenerated barcode');
        } catch (AuthorizationException) {
        }

        $new = app(BarcodeService::class)->assign($product, $this->userWithRole('manager'), regenerate: true);
        $this->assertNotSame('6281007012345', $new->barcode);
    }

    public function test_duplicate_barcodes_are_rejected_by_database(): void
    {
        Product::factory()->create(['barcode' => '2000000000106']);

        $this->expectException(QueryException::class);
        Product::factory()->create(['barcode' => '2000000000106']);
    }

    public function test_barcode_persists_and_unrelated_edits_keep_it(): void
    {
        $product = app(BarcodeService::class)->assign(Product::factory()->create(['barcode' => null]), $this->userWithRole('admin'));
        $code = $product->barcode;

        app(ProductService::class)->update($product, ['name' => 'اسم جديد', 'reorder_level' => 9], $this->userWithRole('inventory_clerk'));

        $this->assertSame($code, $product->refresh()->barcode);
    }

    public function test_clerk_cannot_change_an_existing_barcode_via_update(): void
    {
        $product = Product::factory()->create(['barcode' => '6281007012345']);

        $this->expectException(AuthorizationException::class);
        app(ProductService::class)->update($product, ['barcode' => '999999'], $this->userWithRole('inventory_clerk'));
    }

    public function test_generate_from_create_form_and_save(): void
    {
        $unit = Unit::factory()->create();

        $component = Livewire::actingAs($this->userWithRole('inventory_clerk'))->test(CreateProduct::class)
            ->fillForm(['name' => 'أحمر شفاه', 'sku' => 'LIP-77', 'unit_id' => $unit->id, 'cost_price' => 5, 'sale_price' => 9, 'tax_rate' => 0])
            ->callFormComponentAction('barcode', 'generateBarcode');

        $generated = $component->get('data.barcode');
        $this->assertTrue(BarcodeService::isValidInternal($generated));

        $component->call('create')->assertHasNoFormErrors();
        $this->assertSame($generated, Product::query()->where('sku', 'LIP-77')->value('barcode'));
    }

    public function test_code128_encodes_exact_value_and_scanner_input_finds_product(): void
    {
        $product = app(BarcodeService::class)->assign(Product::factory()->create(['barcode' => null]), $this->userWithRole('admin'));

        // The encoded symbol contains exactly the digits a scanner will type back.
        $barcode = (new TypeCode128)->getBarcode($product->barcode);
        $this->assertSame($product->barcode, $barcode->getBarcode());
        $this->assertGreaterThan(20, count($barcode->getBars()));

        $found = Livewire::actingAs($this->userWithRole('cashier'))->test(Pos::class)->instance()->findByCode($product->barcode);
        $this->assertSame($product->id, $found['id']);
    }

    public function test_label_print_output(): void
    {
        $product = app(BarcodeService::class)->assign(
            Product::factory()->create(['barcode' => null, 'name' => 'عطر فاخر', 'sku' => 'PRF-X', 'sale_price' => 12500]),
            $this->userWithRole('admin'));
        $user = $this->userWithRole('cashier');

        $html = $this->actingAs($user)->get(route('print.barcode-labels', ['ids' => [$product->id], 'copies' => 3, 'size' => 'a4_24', 'show_price' => 1]))
            ->assertOk()->assertSee('عطر فاخر')->assertSee($product->barcode)->assertSee('PRF-X')->assertSee('12,500')
            ->getContent();

        $this->assertSame(3, substr_count($html, 'class="label"'));
        $this->assertSame(3, substr_count($html, '<svg'));

        $this->actingAs($user)->get(route('print.barcode-labels', ['ids' => [$product->id], 'show_price' => 0]))
            ->assertOk()->assertDontSee('12,500');
    }

    public function test_label_print_requires_login_and_valid_size(): void
    {
        $product = Product::factory()->create();
        $this->get(route('print.barcode-labels', ['ids' => [$product->id]]))->assertRedirect('/admin/login');
        $this->actingAs($this->userWithRole('cashier'))
            ->get(route('print.barcode-labels', ['ids' => [$product->id], 'size' => '../../etc']))->assertSessionHasErrors('size');
    }
}
