<?php

namespace App\Policies;

use App\Models\CashMovement;
use App\Models\User;

class CashMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cash_register.view_all');
    }

    public function view(User $user, CashMovement $movement): bool
    {
        return $user->can('cash_register.view_all') || $movement->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CashMovement $movement): bool
    {
        return false;
    }

    public function delete(User $user, CashMovement $movement): bool
    {
        return false;
    }
}
