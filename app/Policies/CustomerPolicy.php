<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can('customers.view');
    }

    /** Account statement exposes debts. */
    public function viewStatement(User $user, Customer $customer): bool
    {
        return $user->can('customers.view') && $user->can('customers.view_debts');
    }

    public function create(User $user): bool
    {
        return $user->can('customers.create');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customers.update');
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->can('customers.delete') && $customer->balance == 0;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Customer $customer): bool
    {
        return $user->can('customers.delete');
    }

    public function forceDelete(User $user, Customer $customer): bool
    {
        return false;
    }
}
