<?php

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;

class PurchasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchases.view');
    }

    public function view(User $user, Purchase $model): bool
    {
        return $user->can('purchases.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchases.create');
    }

    public function update(User $user, Purchase $model): bool
    {
        return $user->can('purchases.create') && $model->isEditable();
    }

    public function delete(User $user, Purchase $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
