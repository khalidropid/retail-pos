<?php

namespace App\Policies;

use App\Models\CashRegister;
use App\Models\User;

class CashRegisterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cash_register.manage');
    }

    public function view(User $user, CashRegister $register): bool
    {
        return $user->can('cash_register.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('cash_register.manage');
    }

    public function update(User $user, CashRegister $register): bool
    {
        return $user->can('cash_register.manage');
    }

    public function delete(User $user, CashRegister $register): bool
    {
        return $user->can('cash_register.manage') && ! $register->sessions()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
