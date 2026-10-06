<?php

namespace App\Filament\Resources\Suppliers\RelationManagers;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Purchase;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PurchasesRelationManager extends RelationManager
{
    protected static string $relationship = 'purchases';

    protected static ?string $title = 'أوامر الشراء';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم'),
                TextColumn::make('order_date')->label('التاريخ')->date('Y-m-d'),
                TextColumn::make('status')->label('الحالة')->badge(),
                TextColumn::make('total')->label('الإجمالي')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('received_total')->label('المستلم')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('paid_amount')->label('المدفوع')->formatStateUsing(fn ($state) => money($state)),
            ])
            ->recordActions([
                Action::make('open')->label('فتح')->url(fn (Purchase $record) => PurchaseResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
