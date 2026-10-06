<?php

namespace Tests\Feature\Foundation;

use App\Filament\Pages\Settings;
use App\Models\AuditLog;
use App\Services\NumberingService;
use App\Services\SettingsService;
use App\Support\ArabicText;
use App\Support\Money;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    public function test_login_page_renders_in_arabic_rtl(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('lang="ar"', false);
    }

    public function test_admin_can_open_dashboard_and_inactive_user_cannot(): void
    {
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin)->get('/admin')->assertOk();

        $inactive = $this->userWithRole('admin', ['is_active' => false]);
        $this->actingAs($inactive)->get('/admin')->assertForbidden();
    }

    public function test_settings_are_read_from_database_not_hardcoded(): void
    {
        settings()->set('store.name', 'سوبرماركت النور');

        $this->assertSame('سوبرماركت النور', settings('store.name'));
    }

    public function test_cashier_cannot_open_settings_users_or_roles(): void
    {
        $cashier = $this->userWithRole('cashier');

        $this->actingAs($cashier)->get('/admin/settings')->assertForbidden();
        $this->actingAs($cashier)->get('/admin/users')->assertForbidden();
        $this->actingAs($cashier)->get('/admin/roles')->assertForbidden();
        $this->assertFalse($cashier->can('roles.manage'));
    }

    public function test_admin_saves_settings_and_change_is_audited(): void
    {
        $admin = $this->userWithRole('admin');

        Livewire::actingAs($admin)->test(Settings::class)
            ->set('data.store__name', 'متجر الاختبار')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('متجر الاختبار', app(SettingsService::class)->get('store.name'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'settings_changed', 'user_id' => $admin->id]);
    }

    public function test_login_is_audited(): void
    {
        $user = $this->userWithRole('cashier', ['password' => 'secret-pass']);

        Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'secret-pass')
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(AuditLog::query()->where('event', 'login')->where('user_id', $user->id)->exists());
    }

    public function test_document_numbers_are_sequential_and_unique(): void
    {
        $numbers = collect(range(1, 5))->map(fn () => app(NumberingService::class)->next('sale'));

        $this->assertSame('INV-000001', $numbers->first());
        $this->assertSame('INV-000005', $numbers->last());
        $this->assertCount(5, $numbers->unique());
    }

    public function test_money_math_is_exact(): void
    {
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('33.33', Money::div('100', '3'));
        $this->assertTrue(Money::gt('100000.01', '100000'));
    }

    public function test_arabic_normalization(): void
    {
        $this->assertSame(ArabicText::normalize('أحمد'), ArabicText::normalize('احمد'));
        $this->assertSame(ArabicText::normalize('مدرسة'), ArabicText::normalize('مدرسه'));
        $this->assertSame('123', ArabicText::normalize('١٢٣'));
    }
}
