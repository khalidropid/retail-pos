<?php

namespace Tests\Feature\Returns;

use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Models\SaleReturn;
use Livewire\Livewire;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class ReturnUiTest extends TestCase
{
    use BuildsDomainData;

    public function test_return_action_on_sale_page(): void
    {
        $manager = $this->userWithRole('manager');
        $this->openRegister($manager, '50000');
        $p = $this->stocked(10, ['sale_price' => '2000']);
        $sale = $this->sell($manager, [[$p, 3]]);

        $component = Livewire::actingAs($manager)->test(ViewSale::class, ['record' => $sale->id])
            ->mountAction('return');

        $lines = $component->get('mountedActions.0.data.lines');
        $key = array_key_first($lines);
        $lines[$key]['quantity'] = 2;

        $component->set('mountedActions.0.data.lines', $lines)
            ->set('mountedActions.0.data.reason', 'تالف')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $return = SaleReturn::query()->firstOrFail();
        $this->assertSame('4000.00', $return->total);
        $this->assertSame('9.000', $p->refresh()->stock_quantity);
    }

    public function test_returns_pages_render_and_cashier_has_no_return_button(): void
    {
        $manager = $this->userWithRole('manager');
        $this->openRegister($manager);
        $sale = $this->sell($manager, [[$this->stocked(5), 1]]);

        $this->actingAs($manager)->get('/admin/sale-returns')->assertOk();

        $cashier = $this->userWithRole('cashier', []);
        $cashier->givePermissionTo('sales.view_all');
        Livewire::actingAs($cashier)->test(ViewSale::class, ['record' => $sale->id])->assertActionHidden('return');
    }
}
