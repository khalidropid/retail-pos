<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SaleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Sale::class);
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        $query = Sale::query()->with(['customer', 'user'])
            ->when(! $request->user()->can('sales.view_all'), fn ($q) => $q->where('user_id', $request->user()->id))
            ->when($request->input('from'), fn ($q, $d) => $q->whereDate('sale_date', '>=', $d))
            ->when($request->input('to'), fn ($q, $d) => $q->whereDate('sale_date', '<=', $d))
            ->latest('id');

        return SaleResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(Sale $sale): SaleResource
    {
        Gate::authorize('view', $sale);

        return new SaleResource($sale->load(['customer', 'user', 'items', 'allocations.payment']));
    }

    public function store(StoreSaleRequest $request, SaleService $sales): JsonResponse
    {
        $sale = $sales->completeSale($request->validated(), $request->user());

        return (new SaleResource($sale->load(['customer', 'user', 'items', 'allocations.payment'])))->response()->setStatusCode(201);
    }
}
