<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LowStockProducts extends TableWidget
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'منتجات منخفضة المخزون';

    public static function canView(): bool
    {
        return auth()->user()?->can('products.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Product::query()->active()->lowStock()->with('unit')->orderBy('stock_quantity'))
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('name')->label('المنتج'),
                TextColumn::make('stock_quantity')->label('المتوفر')->badge()->color('danger')
                    ->formatStateUsing(fn ($state, Product $record) => Money::formatQty($state).' '.$record->unit->short_name),
                TextColumn::make('reorder_level')->label('حد الطلب')->formatStateUsing(fn ($state) => Money::formatQty($state)),
            ])
            ->recordActions([
                Action::make('open')->label('فتح')->url(fn (Product $record) => ProductResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('لا توجد منتجات منخفضة المخزون');
    }
}
