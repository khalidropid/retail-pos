<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Pages\CustomerStatement;
use App\Filament\Resources\CustomerPayments\CustomerPaymentResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pay')->label('تسجيل دفعة')->icon('heroicon-o-banknotes')->color('success')
                ->visible(fn () => Money::isPositive($this->record->balance) && auth()->user()->can('customer_payments.create'))
                ->url(fn () => CustomerPaymentResource::getUrl('create', ['customer' => $this->record->id])),
            Action::make('statement')->label('كشف الحساب')->icon('heroicon-o-document-chart-bar')->color('gray')
                ->visible(fn () => CustomerStatement::canAccess())
                ->url(fn () => CustomerStatement::getUrl(['customer' => $this->record->id])),
            EditAction::make(),
        ];
    }
}
