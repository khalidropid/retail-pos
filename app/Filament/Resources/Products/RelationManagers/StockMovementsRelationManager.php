<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Enums\StockMovementType;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StockMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'stockMovements';

    protected static ?string $title = 'حركات المخزون';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('inventory.view') ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($q) => $q->with('user'))
            ->columns([
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
                TextColumn::make('type')->label('النوع')->badge(),
                TextColumn::make('quantity')->label('الكمية')->formatStateUsing(fn ($state) => Money::formatQty($state))
                    ->color(fn ($state) => Money::isNegative($state) ? 'danger' : 'success'),
                TextColumn::make('quantity_before')->label('قبل')->formatStateUsing(fn ($state) => Money::formatQty($state)),
                TextColumn::make('quantity_after')->label('بعد')->formatStateUsing(fn ($state) => Money::formatQty($state)),
                TextColumn::make('user.name')->label('المستخدم'),
                TextColumn::make('notes')->label('ملاحظات')->limit(40)->placeholder('-'),
            ])
            ->filters([SelectFilter::make('type')->label('النوع')->options(StockMovementType::class)]);
    }
}
