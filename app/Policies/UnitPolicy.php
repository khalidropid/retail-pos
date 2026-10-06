<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;

class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    public function view(User $user, Unit $model): bool
    {
        return $user->can('catalog.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    public function update(User $user, Unit $model): bool
    {
        return $user->can('catalog.manage');
    }

    public function delete(User $user, Unit $model): bool
    {
        return $user->can('catalog.manage') && ! $model->products()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
