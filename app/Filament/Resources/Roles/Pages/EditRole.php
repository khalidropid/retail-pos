<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Services\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** @var array<int, string> */
    protected array $permissionNames = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['permission_names'] = RoleResource::toFormState($this->record->permissions->pluck('name')->all());

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->permissionNames = RoleResource::fromFormState($data['permission_names'] ?? []);
        unset($data['permission_names']);

        return $data;
    }

    protected function afterSave(): void
    {
        $before = $this->record->permissions->pluck('name')->sort()->values()->all();
        $this->record->syncPermissions($this->permissionNames);
        $after = collect($this->permissionNames)->sort()->values()->all();

        if ($before !== $after) {
            app(AuditLogger::class)->log('permissions_changed', $this->record,
                ['permissions' => $before], ['permissions' => $after], description: 'تعديل صلاحيات الدور '.$this->record->label);
        }
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
