<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Sales\SaleResource;
use App\Models\Sale;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestSales extends TableWidget
{
    protected static ?int $sort = 5;

    protected static ?string $heading = 'آخر العمليات';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('sales.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => SaleResource::getEloquentQuery()->latest('id'))
            ->paginated(false)
            ->modifyQueryUsing(fn ($query) => $query->limit(8))
            ->columns([
                TextColumn::make('number')->label('الفاتورة'),
                TextColumn::make('sale_date')->label('الوقت')->since(),
                TextColumn::make('customer.name')->label('العميل')->placeholder('نقدي'),
                TextColumn::make('user.name')->label('الكاشير'),
                TextColumn::make('sale_type')->label('النوع')->badge(),
                TextColumn::make('total')->label('الإجمالي')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('status')->label('الحالة')->badge(),
            ])
            ->recordActions([
                Action::make('open')->label('فتح')->url(fn (Sale $record) => SaleResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
