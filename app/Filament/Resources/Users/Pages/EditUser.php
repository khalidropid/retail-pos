<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var array<int, string> */
    protected array $rolesBefore = [];

    /** @var array<string, mixed> */
    protected array $attributesBefore = [];

    protected function beforeSave(): void
    {
        $this->rolesBefore = $this->record->roles()->pluck('name')->sort()->values()->all();
        $this->attributesBefore = $this->record->only(['name', 'email', 'phone', 'is_active']);
    }

    protected function afterSave(): void
    {
        $audit = app(AuditLogger::class);
        $rolesAfter = $this->record->roles()->pluck('name')->sort()->values()->all();
        $attributesAfter = $this->record->only(['name', 'email', 'phone', 'is_active']);

        if ($attributesAfter != $this->attributesBefore || $this->record->wasChanged('password')) {
            $audit->log('user_changed', $this->record, $this->attributesBefore,
                $attributesAfter + ($this->record->wasChanged('password') ? ['password' => 'تم التغيير'] : []));
        }

        if ($rolesAfter !== $this->rolesBefore) {
            $audit->log('permissions_changed', $this->record, ['roles' => $this->rolesBefore], ['roles' => $rolesAfter],
                description: 'تغيير دور المستخدم');
        }
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
