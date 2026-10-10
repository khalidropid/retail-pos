<?php

namespace App\Policies;

use App\Models\PaymentAccountType;
use App\Models\User;

class PaymentAccountTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payment_methods.manage');
    }

    public function view(User $user, PaymentAccountType $type): bool
    {
        return $user->can('payment_methods.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('payment_methods.manage');
    }

    public function update(User $user, PaymentAccountType $type): bool
    {
        return $user->can('payment_methods.manage');
    }

    /** Deactivate instead of deleting so past payments keep their reference. */
    public function delete(User $user, PaymentAccountType $type): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
