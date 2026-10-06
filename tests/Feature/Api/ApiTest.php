<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Sale;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use BuildsDomainData;

    public function test_token_issue_and_me(): void
    {
        $user = $this->userWithRole('cashier', ['email' => 'c@pos.test', 'password' => 'secret-123']);

        $token = $this->postJson('/api/v1/auth/token', ['email' => 'c@pos.test', 'password' => 'secret-123', 'device_name' => 'tablet'])
            ->assertCreated()->json('token');

        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', 'c@pos.test');
        $this->assertDatabaseHas('audit_logs', ['event' => 'login', 'user_id' => $user->id]);
    }

    public function test_wrong_password_returns_arabic_error_and_login_is_rate_limited(): void
    {
        $this->userWithRole('cashier', ['email' => 'c@pos.test']);

        $this->postJson('/api/v1/auth/token', ['email' => 'c@pos.test', 'password' => 'bad', 'device_name' => 'x'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'بيانات الدخول غير صحيحة.');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/token', ['email' => 'c@pos.test', 'password' => 'bad', 'device_name' => 'x']);
        }
        $this->postJson('/api/v1/auth/token', ['email' => 'c@pos.test', 'password' => 'bad', 'device_name' => 'x'])->assertStatus(429);
    }

    public function test_inactive_user_is_blocked_even_with_token(): void
    {
        $user = $this->userWithRole('cashier');
        Sanctum::actingAs($user);
        $user->update(['is_active' => false]);

        $this->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_unauthenticated_requests_get_401(): void
    {
        $this->getJson('/api/v1/products')->assertUnauthorized();
    }

    public function test_products_pagination_search_and_barcode(): void
    {
        Sanctum::actingAs($this->userWithRole('cashier'));
        $p = $this->stocked(5, ['name' => 'شاي أحمد', 'barcode' => '5011111']);
        $this->stocked(5);

        $this->getJson('/api/v1/products?search=شاي')->assertOk()->assertJsonCount(1, 'data')->assertJsonStructure(['data', 'links', 'meta']);
        $this->getJson('/api/v1/products/code/5011111')->assertOk()->assertJsonPath('data.id', $p->id)
            ->assertJsonMissingPath('data.cost_price');
    }

    public function test_create_sale_via_api_uses_business_rules(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        Sanctum::actingAs($cashier);
        $p = $this->stocked(5, ['sale_price' => '2000']);

        $this->postJson('/api/v1/sales', [
            'sale_type' => 'cash',
            'payment_method_id' => PaymentMethod::cash()->id,
            'items' => [['product_id' => $p->id, 'quantity' => 2]],
        ])->assertCreated()->assertJsonPath('data.total', '4000.00')->assertJsonPath('data.payment_status', 'paid');

        // Credit without customer -> validation error in Arabic.
        $this->postJson('/api/v1/sales', ['sale_type' => 'credit', 'items' => [['product_id' => $p->id, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');

        // Over-stock -> business rule error.
        $this->postJson('/api/v1/sales', ['sale_type' => 'cash', 'payment_method_id' => PaymentMethod::cash()->id,
            'items' => [['product_id' => $p->id, 'quantity' => 50]]])
            ->assertStatus(422)->assertJsonFragment(['message' => 'الكمية المتوفرة من "'.$p->name.'" غير كافية. المتوفر: 3، المطلوب: 50.']);
    }

    public function test_cashier_cannot_read_other_cashiers_sale_idor(): void
    {
        $a = $this->userWithRole('cashier');
        $this->openRegister($a);
        $sale = $this->sell($a, [[$this->stocked(5), 1]]);

        Sanctum::actingAs($this->userWithRole('cashier'));
        $this->getJson("/api/v1/sales/{$sale->id}")->assertForbidden();
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_customer_payment_via_api_and_debt_visibility(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $this->sell($cashier, [[$this->stocked(5, ['sale_price' => '10000']), 1]], 'credit', $customer, ['paid_amount' => 0]);

        Sanctum::actingAs($cashier);
        $this->getJson("/api/v1/customers/{$customer->id}")->assertOk()->assertJsonMissingPath('data.balance');
        $this->getJson("/api/v1/customers/{$customer->id}/statement")->assertForbidden();

        $this->postJson("/api/v1/customers/{$customer->id}/payments", [
            'amount' => 4000, 'payment_method_id' => PaymentMethod::cash()->id,
        ])->assertCreated()->assertJsonPath('data.balance_after', '6000.00');

        $this->postJson("/api/v1/customers/{$customer->id}/payments", [
            'amount' => 999999, 'payment_method_id' => PaymentMethod::cash()->id,
        ])->assertStatus(422);

        Sanctum::actingAs($this->userWithRole('manager'));
        $this->getJson("/api/v1/customers/{$customer->id}/statement")->assertOk()->assertJsonPath('closing_balance', '6000.00');
    }

    public function test_clerk_cannot_create_sales_via_api(): void
    {
        Sanctum::actingAs($this->userWithRole('inventory_clerk'));
        $p = $this->stocked(5);

        $this->postJson('/api/v1/sales', ['sale_type' => 'cash', 'items' => [['product_id' => $p->id, 'quantity' => 1]]])
            ->assertForbidden();
        $this->assertSame(0, Sale::query()->count());
    }
}
