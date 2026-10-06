<?php

namespace Tests\Feature\Security;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Services\CustomerPaymentService;
use App\Services\InventoryService;
use App\Services\SaleService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use BuildsDomainData;

    public function test_security_headers_present(): void
    {
        $this->get('/admin/login')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_passwords_are_hashed(): void
    {
        $user = User::factory()->create(['password' => 'plain-secret']);

        $this->assertNotSame('plain-secret', $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('plain-secret', $user->password));
    }

    public function test_mass_assignment_cannot_set_balances_or_stock(): void
    {
        // Outside production a guarded attribute raises instead of being ignored.
        try {
            Customer::query()->create(['name' => 'x', 'balance' => 99999]);
            $this->fail('balance was mass-assignable');
        } catch (MassAssignmentException) {
        }
        $this->assertSame(0, Customer::query()->count());

        // In production mode it is silently dropped, never written.
        Model::preventSilentlyDiscardingAttributes(false);
        try {
            $customer = Customer::query()->create(['name' => 'x', 'balance' => 99999]);
            $this->assertSame('0.00', $customer->refresh()->balance);

            $product = Product::factory()->create();
            $product->fill(['stock_quantity' => 500])->save();
            $this->assertSame('0.000', $product->refresh()->stock_quantity);
        } finally {
            Model::preventSilentlyDiscardingAttributes(true);
        }
    }

    public function test_csrf_protection_is_active_on_web_routes(): void
    {
        $this->assertContains(PreventRequestForgery::class,
            Filament::getPanel('admin')->getMiddleware());
    }

    /** Spec §41 permission checks, enforced in the backend services. */
    public function test_permission_matrix(): void
    {
        $cashier = $this->userWithRole('cashier');
        $clerk = $this->userWithRole('inventory_clerk');
        $product = $this->stocked(10);

        // Cashier cannot change roles/permissions.
        $this->assertFalse($cashier->can('roles.manage'));
        $this->actingAs($cashier)->get('/admin/roles/create')->assertForbidden();

        // Cashier cannot reverse a payment.
        $this->openRegister($cashier);
        $customer = Customer::factory()->create(['credit_limit' => '100000']);
        $this->sell($cashier, [[$product, 1]], 'credit', $customer, ['paid_amount' => 0]);
        $payment = app(CustomerPaymentService::class)->recordCustomerPayment($customer, '100', PaymentMethod::cash()->id, $cashier);
        try {
            app(CustomerPaymentService::class)->reversePayment($payment, 'x', $cashier);
            $this->fail('cashier reversed a payment');
        } catch (AuthorizationException) {
        }

        // Unauthorized user cannot adjust stock (cashier) — clerk can.
        try {
            app(InventoryService::class)->adjustStock([['product_id' => $product->id, 'quantity' => '1']], 'x', $cashier);
            $this->fail('cashier adjusted stock');
        } catch (AuthorizationException) {
        }
        app(InventoryService::class)->adjustStock([['product_id' => $product->id, 'quantity' => '1']], 'x', $clerk);

        // Unauthorized user cannot override the credit limit.
        $tight = Customer::factory()->create(['credit_limit' => '1']);
        try {
            $this->sell($cashier, [[$product, 1]], 'credit', $tight, ['paid_amount' => 0, 'override_credit_limit' => true]);
            $this->fail('cashier overrode credit limit');
        } catch (BusinessException) {
        }

        // Clerk cannot sell at all.
        $this->expectException(AuthorizationException::class);
        app(SaleService::class)->completeSale(['items' => [['product_id' => $product->id, 'quantity' => 1]]], $clerk);
    }
}
