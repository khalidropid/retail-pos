<?php

namespace App\Filament\Resources\StockMovements;

use App\Enums\StockMovementType;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Models\StockMovement;
use App\Support\Money;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'المخزون';

    protected static ?string $modelLabel = 'حركة مخزون';

    protected static ?string $pluralModelLabel = 'سجل حركات المخزون';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('product.name')->label('المنتج')->searchable()->wrap(),
                TextColumn::make('type')->label('نوع الحركة')->badge(),
                TextColumn::make('quantity')->label('الكمية')->formatStateUsing(fn ($state) => Money::formatQty($state))
                    ->color(fn ($state) => Money::isNegative($state) ? 'danger' : 'success')->weight('bold'),
                TextColumn::make('quantity_before')->label('قبل الحركة')->formatStateUsing(fn ($state) => Money::formatQty($state)),
                TextColumn::make('quantity_after')->label('بعد الحركة')->formatStateUsing(fn ($state) => Money::formatQty($state)),
                TextColumn::make('reference')->label('المرجع')->state(fn (StockMovement $record) => $record->reference?->number ?? ($record->reference_id ? '#'.$record->reference_id : '-')),
                TextColumn::make('user.name')->label('المستخدم'),
                TextColumn::make('notes')->label('ملاحظات')->limit(40)->placeholder('-')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->label('نوع الحركة')->options(StockMovementType::class)->multiple(),
                SelectFilter::make('product_id')->label('المنتج')->relationship('product', 'name')->searchable(),
                SelectFilter::make('user_id')->label('المستخدم')->relationship('user', 'name'),
                Filter::make('date')->schema([
                    DatePicker::make('from')->label('من تاريخ'),
                    DatePicker::make('until')->label('إلى تاريخ'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['product', 'user', 'reference']);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockMovements::route('/')];
    }
}
