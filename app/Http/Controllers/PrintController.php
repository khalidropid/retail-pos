<?php

namespace App\Http\Controllers;

use App\Filament\Pages\Reports\CustomerReports;
use App\Filament\Pages\Reports\FinancialReports;
use App\Filament\Pages\Reports\InventoryReports;
use App\Filament\Pages\Reports\PurchaseReports;
use App\Filament\Pages\Reports\SalesReports;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Services\CustomerStatementService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Mpdf\Mpdf;

class PrintController extends Controller
{
    public function saleReceipt(Sale $sale): View
    {
        Gate::authorize('view', $sale);

        $sale->load(['items', 'customer', 'user', 'paymentMethod']);

        return view('print.sale-receipt', ['sale' => $sale]);
    }

    public function paymentReceipt(CustomerPayment $payment): View
    {
        Gate::authorize('view', $payment);

        $payment->load(['customer', 'user', 'paymentMethod', 'allocations.sale']);

        return view('print.payment-receipt', ['payment' => $payment]);
    }

    public function customerStatement(Request $request, Customer $customer): View|Response
    {
        Gate::authorize('viewStatement', $customer);

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'format' => ['nullable', 'in:html,pdf'],
        ]);

        $statement = app(CustomerStatementService::class)->statement($customer, $data['from'] ?? null, $data['to'] ?? null);
        $summary = app(CustomerStatementService::class)->summary($customer);

        if (($data['format'] ?? 'html') === 'pdf') {
            $html = view('print.customer-statement-pdf', ['s' => $statement, 'summary' => $summary])->render();

            return $this->pdf($html, 'statement-'.$customer->id.'.pdf');
        }

        return view('print.customer-statement', ['s' => $statement, 'summary' => $summary]);
    }

    public function report(Request $request): View
    {
        $data = $request->validate([
            'page' => ['required', 'string'],
            'report' => ['required', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $pages = collect([
            SalesReports::class,
            InventoryReports::class,
            PurchaseReports::class,
            CustomerReports::class,
            FinancialReports::class,
        ])->keyBy(fn ($class) => $class::getSlug());

        $page = $pages->get($data['page']) ?? abort(404);
        abort_unless($page::canAccess(), 403);

        return view('print.report', [
            'r' => $page::buildReport($data['report'], $data['from'] ?? null, $data['to'] ?? null),
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
        ]);
    }

    private function pdf(string $html, string $filename): Response
    {
        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'dejavusans',
            'tempDir' => $tempDir,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_left' => 10,
            'margin_right' => 10,
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        $mpdf->WriteHTML($html);

        return response($mpdf->Output($filename, 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
