<?php

namespace App\Policies;

use App\Models\SupplierPayment;
use App\Models\User;

class SupplierPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('suppliers.view');
    }

    public function view(User $user, SupplierPayment $model): bool
    {
        return $user->can('suppliers.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SupplierPayment $model): bool
    {
        return false;
    }

    public function delete(User $user, SupplierPayment $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
