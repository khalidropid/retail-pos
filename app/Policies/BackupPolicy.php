<?php

namespace App\Policies;

use App\Models\Backup;
use App\Models\User;

class BackupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('backups.manage') || $user->can('backups.restore');
    }

    public function view(User $user, Backup $backup): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Backup $backup): bool
    {
        return false;
    }

    public function delete(User $user, Backup $backup): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
