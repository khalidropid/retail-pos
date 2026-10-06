<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('suppliers.view');
    }

    public function view(User $user, Supplier $model): bool
    {
        return $user->can('suppliers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('suppliers.manage');
    }

    public function update(User $user, Supplier $model): bool
    {
        return $user->can('suppliers.manage');
    }

    public function delete(User $user, Supplier $model): bool
    {
        return $user->can('suppliers.manage') && ! $model->purchases()->exists() && $model->balance == 0;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
