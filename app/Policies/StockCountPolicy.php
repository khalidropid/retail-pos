<?php

namespace App\Policies;

use App\Models\StockCount;
use App\Models\User;

class StockCountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.count') || $user->can('inventory.view');
    }

    public function view(User $user, StockCount $count): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.count');
    }

    public function update(User $user, StockCount $count): bool
    {
        return false;
    }

    public function delete(User $user, StockCount $count): bool
    {
        return false;
    }
}
