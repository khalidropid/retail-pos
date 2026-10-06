<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

class SalePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.view');
    }

    /** Without sales.view_all a cashier only sees their own invoices. */
    public function view(User $user, Sale $sale): bool
    {
        return $user->can('sales.view_all') || ($user->can('sales.view') && $sale->user_id === $user->id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Sale $sale): bool
    {
        return false;
    }

    public function delete(User $user, Sale $sale): bool
    {
        return false;
    }
}
