<?php

namespace App\Filament\Resources\PaymentAccountTypes\Pages;

use App\Filament\Resources\PaymentAccountTypes\PaymentAccountTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePaymentAccountTypes extends ManageRecords
{
    protected static string $resource = PaymentAccountTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
