<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PrintController extends Controller
{
    public function saleReceipt(Sale $sale): View
    {
        Gate::authorize('view', $sale);

        $sale->load(['items', 'customer', 'user', 'paymentMethod']);

        return view('print.sale-receipt', ['sale' => $sale]);
    }
}
