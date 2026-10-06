<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    public const PROTECTED = ['admin'];

    public function getLabelAttribute(): string
    {
        return config("pos_permissions.roles.{$this->name}.label", $this->name);
    }

    public function isProtected(): bool
    {
        return in_array($this->name, self::PROTECTED, true);
    }
}
