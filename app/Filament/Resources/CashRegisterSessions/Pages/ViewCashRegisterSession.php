<?php

namespace App\Filament\Resources\CashRegisterSessions\Pages;

use App\Filament\Resources\CashRegisterSessions\CashRegisterSessionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCashRegisterSession extends ViewRecord
{
    protected static string $resource = CashRegisterSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CashRegisterSessionResource::cashInOutAction('in'),
            CashRegisterSessionResource::cashInOutAction('out'),
            CashRegisterSessionResource::closeAction(),
        ];
    }
}
