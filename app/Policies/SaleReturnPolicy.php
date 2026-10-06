<?php

namespace App\Policies;

use App\Models\SaleReturn;
use App\Models\User;

class SaleReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('returns.view');
    }

    public function view(User $user, SaleReturn $return): bool
    {
        return $user->can('returns.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SaleReturn $return): bool
    {
        return false;
    }

    public function delete(User $user, SaleReturn $return): bool
    {
        return false;
    }
}
