<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Sales\SaleResource;
use App\Models\Sale;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesRelationManager extends RelationManager
{
    protected static string $relationship = 'sales';

    protected static ?string $title = 'الفواتير (سجل المشتريات)';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('الفاتورة'),
                TextColumn::make('sale_date')->label('التاريخ')->date('Y-m-d'),
                TextColumn::make('sale_type')->label('النوع')->badge(),
                TextColumn::make('total')->label('الإجمالي')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('paid_amount')->label('المدفوع')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('returned_amount')->label('المرتجع')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('balance_due')->label('المتبقي')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('due_date')->label('الاستحقاق')->date('Y-m-d')->placeholder('-'),
                TextColumn::make('payment_state')->label('الدفع')->badge()->state(fn (Sale $record) => $record->displayPaymentStatus()),
                TextColumn::make('status')->label('الحالة')->badge(),
            ])
            ->filters([
                SelectFilter::make('payment_status')->label('حالة الدفع')->options(PaymentStatus::class)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'overdue' => $query->overdue(),
                        default => $query->notCancelled()->where('payment_status', $data['value']),
                    }),
            ])
            ->recordActions([
                Action::make('open')->label('فتح')->url(fn (Sale $record) => SaleResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
