<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SaleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/token', [AuthController::class, 'issue'])->middleware('throttle:api-login')->name('auth.token');

    Route::middleware(['auth:sanctum', 'throttle:api', 'active'])->group(function () {
        Route::delete('auth/token', [AuthController::class, 'revoke'])->name('auth.revoke');
        Route::get('me', [AuthController::class, 'me'])->name('me');

        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/code/{code}', [ProductController::class, 'byCode'])->name('products.by-code');
        Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');

        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::get('customers/{customer}/statement', [CustomerController::class, 'statement'])->name('customers.statement');
        Route::post('customers/{customer}/payments', [CustomerController::class, 'storePayment'])->name('customers.payments.store');

        Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
        Route::post('sales', [SaleController::class, 'store'])->name('sales.store');
        Route::get('sales/{sale}', [SaleController::class, 'show'])->name('sales.show');
    });
});
