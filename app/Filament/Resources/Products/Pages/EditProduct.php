<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Domain;
use App\Services\ProductService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Domain::run(fn () => app(ProductService::class)->update($record, $data, auth()->user()));
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }
}
