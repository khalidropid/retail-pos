<?php

namespace App\Filament\Resources\CustomerPayments\Pages;

use App\Filament\Resources\CustomerPayments\CustomerPaymentResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomerPayment extends ViewRecord
{
    protected static string $resource = CustomerPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [CustomerPaymentResource::printAction(), CustomerPaymentResource::reverseAction()];
    }
}
