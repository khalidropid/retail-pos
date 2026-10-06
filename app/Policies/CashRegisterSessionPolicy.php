<?php

namespace App\Policies;

use App\Models\CashRegisterSession;
use App\Models\User;

class CashRegisterSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cash_register.open') || $user->can('cash_register.view_all');
    }

    /** Cashiers see only their own sessions (IDOR protection). */
    public function view(User $user, CashRegisterSession $session): bool
    {
        return $session->user_id === $user->id || $user->can('cash_register.view_all');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CashRegisterSession $session): bool
    {
        return false;
    }

    public function delete(User $user, CashRegisterSession $session): bool
    {
        return false;
    }
}
