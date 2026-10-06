<?php

namespace App\Filament\Resources\StockCounts\Pages;

use App\Filament\Resources\StockCounts\StockCountResource;
use App\Filament\Support\Domain;
use App\Services\InventoryService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStockCount extends CreateRecord
{
    protected static string $resource = StockCountResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Domain::run(fn () => app(InventoryService::class)->createCount(
            array_values($data['items']), auth()->user(), $data['notes'] ?? null));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
