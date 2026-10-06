<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /** @var array<int, string> */
    protected array $permissionNames = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->permissionNames = RoleResource::fromFormState($data['permission_names'] ?? []);
        unset($data['permission_names']);
        $data['guard_name'] = 'web';

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncPermissions($this->permissionNames);
        app(AuditLogger::class)->log('permissions_changed', $this->record, null,
            ['role' => $this->record->name, 'permissions' => $this->permissionNames], description: 'إنشاء دور');
    }
}
