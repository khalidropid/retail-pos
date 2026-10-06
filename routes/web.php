<?php

use App\Http\Controllers\PrintController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware(['auth'])->prefix('print')->name('print.')->group(function () {
    Route::get('sales/{sale}/receipt', [PrintController::class, 'saleReceipt'])->name('sale-receipt');
});
