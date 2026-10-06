<?php

namespace App\Policies;

use App\Models\PaymentMethod;
use App\Models\User;

class PaymentMethodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payment_methods.manage');
    }

    public function view(User $user, PaymentMethod $model): bool
    {
        return $user->can('payment_methods.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('payment_methods.manage');
    }

    public function update(User $user, PaymentMethod $model): bool
    {
        return $user->can('payment_methods.manage');
    }

    public function delete(User $user, PaymentMethod $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
