<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        app(AuditLogger::class)->log('user_changed', $this->record,
            new: $this->record->only(['name', 'email', 'phone', 'is_active']) + ['roles' => $this->record->roles->pluck('name')->all()],
            description: 'إنشاء مستخدم');
    }
}
