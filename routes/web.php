<?php

use App\Http\Controllers\PrintController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware(['auth', 'throttle:60,1'])->prefix('print')->name('print.')->group(function () {
    Route::get('sales/{sale}/receipt', [PrintController::class, 'saleReceipt'])->name('sale-receipt');
    Route::get('payments/{payment}/receipt', [PrintController::class, 'paymentReceipt'])->name('payment-receipt');
    Route::get('customers/{customer}/statement', [PrintController::class, 'customerStatement'])->name('customer-statement');
});
