<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Support\Domain;
use App\Services\PurchaseService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPurchase extends EditRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['items'] = $this->record->items()->get(['product_id', 'quantity', 'unit_cost', 'tax_rate'])->toArray();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $data['items'] = array_values($data['items'] ?? []);

        return Domain::run(fn () => app(PurchaseService::class)->update($record, $data, auth()->user()));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
