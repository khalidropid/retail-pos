<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;

class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    public function view(User $user, Brand $model): bool
    {
        return $user->can('catalog.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    public function update(User $user, Brand $model): bool
    {
        return $user->can('catalog.manage');
    }

    public function delete(User $user, Brand $model): bool
    {
        return $user->can('catalog.manage') && ! $model->products()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
