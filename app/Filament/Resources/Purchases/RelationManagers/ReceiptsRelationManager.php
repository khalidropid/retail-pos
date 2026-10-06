<?php

namespace App\Filament\Resources\Purchases\RelationManagers;

use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReceiptsRelationManager extends RelationManager
{
    protected static string $relationship = 'receipts';

    protected static ?string $title = 'سندات الاستلام';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'items.product']))
            ->columns([
                TextColumn::make('number')->label('الرقم'),
                TextColumn::make('received_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
                TextColumn::make('items_summary')->label('الأصناف')->wrap()
                    ->state(fn ($record) => $record->items->map(fn ($i) => $i->product->name.' × '.Money::formatQty($i->quantity))->implode('، ')),
                TextColumn::make('total')->label('الإجمالي')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('user.name')->label('المستخدم'),
            ]);
    }
}
