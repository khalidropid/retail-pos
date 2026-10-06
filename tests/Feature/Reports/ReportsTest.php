<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\Reports\CustomerReports;
use App\Filament\Pages\Reports\FinancialReports;
use App\Filament\Pages\Reports\InventoryReports;
use App\Filament\Pages\Reports\PurchaseReports;
use App\Filament\Pages\Reports\SalesReports;
use App\Models\Customer;
use App\Models\Role;
use App\Services\DashboardService;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Support\ExcelExporter;
use App\Support\Money;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;
use ZipArchive;

class ReportsTest extends TestCase
{
    use BuildsDomainData;

    private function seedActivity(): array
    {
        $manager = $this->userWithRole('manager');
        $this->openRegister($manager, '0');
        $p = $this->stocked(20, ['name' => 'أرز', 'sale_price' => '1000', 'cost_price' => '600']);
        $cash = $this->sell($manager, [[$p, 5]]);
        $customer = Customer::factory()->create(['name' => 'زبون آجل', 'credit_limit' => '1000000']);
        $this->sell($manager, [[$p, 3]], 'credit', $customer, ['paid_amount' => 0]);
        app(ReturnService::class)->returnSale($cash, [['sale_item_id' => $cash->items->first()->id, 'quantity' => 1]], 'x', $manager);

        return [$manager, $p];
    }

    public function test_sales_report_net_and_profit_after_returns(): void
    {
        $this->seedActivity();

        $r = app(ReportService::class)->salesByPeriod(null, null, 'day', true);
        $row = $r['rows']->first();

        $this->assertSame(2, $row['invoices']);
        $this->assertSame('8000.00', Money::round($row['gross']));
        $this->assertSame('1000.00', Money::round($row['returns']));
        $this->assertSame('7000.00', Money::round($row['net']));
        $this->assertSame('4200.00', $row['cost']);   // 7 net units x 600
        $this->assertSame('2800.00', $row['profit']); // 7000 - 4200

        $byProduct = app(ReportService::class)->salesByProduct(null, null, true);
        $this->assertSame('7', $byProduct['rows']->first()['qty']);
    }

    public function test_every_report_runs_for_admin(): void
    {
        $this->seedActivity();
        $this->actingAs($this->userWithRole('admin'));

        foreach ([SalesReports::class, InventoryReports::class, PurchaseReports::class, CustomerReports::class, FinancialReports::class] as $page) {
            foreach (array_keys($page::definitions()) as $key) {
                $r = $page::buildReport($key, today()->startOfMonth()->toDateString(), today()->toDateString());
                $this->assertNotEmpty($r['columns'], "{$page}:{$key}");
            }
            $this->get($page::getUrl())->assertOk();
        }
    }

    public function test_profit_columns_hidden_without_permission(): void
    {
        $this->seedActivity();
        $role = Role::findOrCreate('report_viewer', 'web');
        $role->syncPermissions(['reports.view']);
        $this->actingAs($this->userWithRole('report_viewer'));

        $r = SalesReports::buildReport('daily', null, null);
        $this->assertArrayNotHasKey('profit', $r['columns']);
        $this->assertArrayNotHasKey('cost', $r['columns']);
    }

    public function test_cashier_cannot_open_reports(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->actingAs($cashier)->get(SalesReports::getUrl())->assertForbidden();
        $this->actingAs($cashier)->get(route('print.report', ['page' => 'reports/sales', 'report' => 'daily']))->assertForbidden();
    }

    public function test_excel_export_is_rtl_xlsx_with_numeric_money(): void
    {
        [$manager] = $this->seedActivity();

        $response = Livewire::actingAs($manager)->test(SalesReports::class)
            ->set('report', 'by_product')
            ->call('exportExcel');
        $response->assertFileDownloaded();

        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        app(ReportService::class); // ensure container booted
        $download = SalesReports::buildReport('by_product', null, null);
        $file = ExcelExporter::download($download, $download['title'], 'r.xlsx')->getFile()->getPathname();
        copy($file, $path);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertMatchesRegularExpression('/rightToLeft="(1|true)"/', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();

        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        $rows = array_values(array_filter($rows, fn ($r) => $r !== [] && $r !== ['']));
        $this->assertSame('المبيعات حسب المنتج', $rows[0][0]);
        $this->assertSame('المنتج', $rows[2][0]);
        $this->assertSame('أرز', $rows[3][0]);
        $this->assertTrue(is_int($rows[3][3]) || is_float($rows[3][3]), 'revenue is a number cell, not text');
        $this->assertEquals(7000, $rows[3][3]);
        $this->assertSame('الإجمالي', end($rows)[0]);
    }

    public function test_report_print_page(): void
    {
        [$manager] = $this->seedActivity();

        $this->actingAs($manager)->get(route('print.report', ['page' => 'reports/sales', 'report' => 'by_product']))
            ->assertOk()->assertSee('المبيعات حسب المنتج')->assertSee('أرز');
    }

    public function test_dashboard_metrics_and_render(): void
    {
        [$manager] = $this->seedActivity();

        $m = app(DashboardService::class)->metrics();
        $this->assertSame('8000.00', $m['sales_today']);
        $this->assertSame(2, $m['invoices_today']);
        $this->assertSame('3000.00', $m['credit_today']);
        $this->assertSame('7000.00', $m['net_today']);
        $this->assertSame('3000.00', $m['receivables']);
        $this->assertSame('2800.00', $m['profit_today']);

        $this->actingAs($manager)->get('/admin')->assertOk();
        $this->actingAs($this->userWithRole('cashier'))->get('/admin')->assertOk();
    }
}
