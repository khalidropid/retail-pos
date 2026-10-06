<?php

namespace Tests\Feature\Sales;

use App\Filament\Pages\Pos;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Sale;
use Livewire\Livewire;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class PosPageTest extends TestCase
{
    use BuildsDomainData;

    public function test_pos_renders_for_cashier_and_is_forbidden_for_clerk(): void
    {
        $this->actingAs($this->userWithRole('cashier'))->get('/admin/pos')->assertOk()->assertSee('إتمام البيع');
        $this->actingAs($this->userWithRole('inventory_clerk'))->get('/admin/pos')->assertForbidden();
    }

    public function test_barcode_lookup_and_search(): void
    {
        $cashier = $this->userWithRole('cashier');
        $p = $this->stocked(5, ['name' => 'حليب المراعي', 'barcode' => '6281007012345']);

        $component = Livewire::actingAs($cashier)->test(Pos::class);

        $this->assertSame($p->id, $component->instance()->findByCode('6281007012345')['id']);
        $this->assertNull($component->instance()->findByCode('000'));
        $this->assertCount(1, $component->instance()->searchProducts('حليب'));
    }

    public function test_checkout_through_pos_component(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier, '0');
        $p = $this->stocked(5, ['sale_price' => '1500']);

        $result = Livewire::actingAs($cashier)->test(Pos::class)->instance()->checkout([
            'items' => [['id' => $p->id, 'quantity' => 2, 'price' => 1, 'priceEdited' => false, 'discount' => 0]],
            'saleType' => 'cash',
            'paymentMethodId' => PaymentMethod::cash()->id,
            'tendered' => '5000',
        ]);

        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertSame('3000.00', $result['sale']['total'], 'price comes from DB, not from the client');
        $this->assertSame('2000.00', $result['sale']['change']);
        $this->assertStringContainsString('/print/sales/', $result['receiptUrl']);
    }

    public function test_checkout_errors_are_returned_in_arabic(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $p = $this->stocked(5);

        $result = Livewire::actingAs($cashier)->test(Pos::class)->instance()->checkout([
            'items' => [['id' => $p->id, 'quantity' => 1]],
            'saleType' => 'credit',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('يجب اختيار العميل', $result['message']);
    }

    public function test_customer_search_shows_credit_info(): void
    {
        $cashier = $this->userWithRole('cashier');
        Customer::factory()->create(['name' => 'محمد كريم', 'credit_limit' => '300000']);

        $results = Livewire::actingAs($cashier)->test(Pos::class)->instance()->searchCustomers('محمد');

        $this->assertSame(300000.0, $results[0]['creditLimit']);
        $this->assertSame(300000.0, $results[0]['available']);
    }

    public function test_receipt_prints_and_other_cashier_cannot_open_it(): void
    {
        $a = $this->userWithRole('cashier');
        $this->openRegister($a);
        $sale = $this->sell($a, [[$this->stocked(5, ['name' => 'سكر']), 1]]);

        $this->actingAs($a)->get(route('print.sale-receipt', $sale))->assertOk()
            ->assertSee($sale->number)->assertSee('سكر')->assertSee('dir="rtl"', false);

        $this->actingAs($this->userWithRole('cashier'))->get(route('print.sale-receipt', $sale))->assertForbidden();
        $this->actingAs($this->userWithRole('manager'))->get(route('print.sale-receipt', $sale))->assertOk();
    }

    public function test_guest_is_redirected_to_login_from_print(): void
    {
        $sale = Sale::query()->first();
        $this->get('/print/sales/1/receipt')->assertRedirect('/admin/login');
    }

    public function test_credit_receipt_shows_due_date(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $customer = Customer::factory()->create();
        $sale = $this->sell($cashier, [[$this->stocked(5), 1]], 'credit', $customer, ['paid_amount' => 0]);

        $this->actingAs($cashier)->get(route('print.sale-receipt', $sale))
            ->assertSee('تاريخ الاستحقاق')->assertSee($sale->due_date->format('Y-m-d'));
    }
}
