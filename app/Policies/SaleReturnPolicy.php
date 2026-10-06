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

    /** Without sales.view_all only returns on the user's own invoices or done by the user. */
    public function view(User $user, SaleReturn $return): bool
    {
        return $user->can('returns.view')
            && ($user->can('sales.view_all') || $return->user_id === $user->id || $return->sale?->user_id === $user->id);
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
