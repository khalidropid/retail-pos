<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Product::class);
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return ProductResource::collection(
            Product::query()->with(['category', 'unit'])
                ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
                ->search($request->input('search'))
                ->orderBy('name')
                ->paginate($request->integer('per_page', 25))
        );
    }

    public function show(Product $product): ProductResource
    {
        Gate::authorize('view', $product);

        return new ProductResource($product->load(['category', 'unit']));
    }

    public function byCode(string $code): ProductResource
    {
        Gate::authorize('viewAny', Product::class);

        $product = Product::query()->active()->with(['category', 'unit'])
            ->where(fn ($q) => $q->where('barcode', $code)->orWhereRaw('lower(sku) = lower(?)', [$code]))
            ->firstOrFail();

        return new ProductResource($product);
    }
}
