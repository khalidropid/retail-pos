<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Support\Domain;
use App\Services\PurchaseService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePurchase extends CreateRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $data['items'] = array_values($data['items'] ?? []);

        return Domain::run(fn () => app(PurchaseService::class)->create($data, auth()->user()));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
