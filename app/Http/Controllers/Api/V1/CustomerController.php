<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCustomerPaymentRequest;
use App\Http\Resources\CustomerPaymentResource;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerPaymentService;
use App\Services\CustomerStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Customer::class);
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return CustomerResource::collection(
            Customer::query()->search($request->input('search'))->orderBy('name')->paginate($request->integer('per_page', 25))
        );
    }

    public function show(Customer $customer): CustomerResource
    {
        Gate::authorize('view', $customer);

        return new CustomerResource($customer);
    }

    public function statement(Request $request, Customer $customer, CustomerStatementService $statements): JsonResponse
    {
        Gate::authorize('viewStatement', $customer);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        $s = $statements->statement($customer, $data['from'] ?? null, $data['to'] ?? null);

        return response()->json([
            'customer' => new CustomerResource($customer),
            'opening_balance' => $s['opening_balance'],
            'total_debit' => $s['total_debit'],
            'total_credit' => $s['total_credit'],
            'closing_balance' => $s['closing_balance'],
            'entries' => $s['entries']->map(fn ($e) => [
                'date' => $e->entry_date->toIso8601String(),
                'type' => $e->type->value,
                'description' => $e->description,
                'debit' => $e->debit,
                'credit' => $e->credit,
                'balance' => $e->balance_after,
            ]),
        ]);
    }

    public function storePayment(StoreCustomerPaymentRequest $request, Customer $customer, CustomerPaymentService $payments): JsonResponse
    {
        $allocations = $request->has('allocations')
            ? collect($request->input('allocations'))->mapWithKeys(fn ($a) => [(int) $a['sale_id'] => (string) $a['amount']])->all()
            : null;

        $payment = $payments->recordCustomerPayment($customer, (string) $request->input('amount'), $request->integer('payment_method_id'),
            $request->user(), $allocations, $request->input('reference'), $request->input('notes'), $request->input('account_type_id'));

        return (new CustomerPaymentResource($payment->load(['paymentMethod', 'allocations'])))->response()->setStatusCode(201);
    }
}
