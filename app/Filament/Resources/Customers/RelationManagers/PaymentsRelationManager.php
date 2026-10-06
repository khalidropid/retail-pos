<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\CustomerPayments\CustomerPaymentResource;
use App\Models\CustomerPayment;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'سجل الدفعات';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('customer_payments.view') ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['paymentMethod', 'user', 'allocations.sale']))
            ->columns([
                TextColumn::make('number')->label('السند'),
                TextColumn::make('payment_date')->label('التاريخ')->dateTime('Y-m-d H:i'),
                TextColumn::make('amount')->label('المبلغ')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('paymentMethod.name')->label('الطريقة'),
                TextColumn::make('invoices')->label('الفواتير')->wrap()
                    ->state(fn (CustomerPayment $record) => $record->allocations->map(fn ($a) => $a->sale->number)->implode('، ')),
                TextColumn::make('user.name')->label('الموظف'),
                TextColumn::make('status')->label('الحالة')->badge(),
            ])
            ->recordActions([
                Action::make('open')->label('فتح')->url(fn (CustomerPayment $record) => CustomerPaymentResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
