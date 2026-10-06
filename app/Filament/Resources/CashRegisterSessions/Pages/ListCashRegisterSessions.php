<?php

namespace App\Filament\Resources\CashRegisterSessions\Pages;

use App\Filament\Resources\CashRegisterSessions\CashRegisterSessionResource;
use App\Services\CashRegisterService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListCashRegisterSessions extends ListRecords
{
    protected static string $resource = CashRegisterSessionResource::class;

    protected function getHeaderActions(): array
    {
        $current = app(CashRegisterService::class)->currentSession(auth()->user());

        return [
            CashRegisterSessionResource::openAction(),
            Action::make('mySession')->label('صندوقي المفتوح')->icon('heroicon-o-inbox')->color('gray')
                ->visible((bool) $current)
                ->url(fn () => $current ? CashRegisterSessionResource::getUrl('view', ['record' => $current]) : null),
        ];
    }
}
