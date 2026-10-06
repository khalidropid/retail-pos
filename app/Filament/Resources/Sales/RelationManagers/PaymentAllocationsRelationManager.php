<?php

namespace App\Filament\Resources\Sales\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentAllocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'allocations';

    protected static ?string $title = 'الدفعات على الفاتورة';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->modifyQueryUsing(fn ($query) => $query->with(['payment.paymentMethod', 'payment.user']))
            ->columns([
                TextColumn::make('payment.number')->label('رقم السند'),
                TextColumn::make('payment.payment_date')->label('التاريخ')->dateTime('Y-m-d H:i'),
                TextColumn::make('amount')->label('المبلغ المخصص')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('payment.paymentMethod.name')->label('طريقة الدفع'),
                TextColumn::make('payment.status')->label('الحالة')->badge(),
                TextColumn::make('payment.user.name')->label('المستخدم'),
            ]);
    }
}
