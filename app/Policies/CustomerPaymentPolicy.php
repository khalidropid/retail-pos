<?php

namespace App\Policies;

use App\Models\CustomerPayment;
use App\Models\User;

class CustomerPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customer_payments.view');
    }

    public function view(User $user, CustomerPayment $payment): bool
    {
        return $user->can('customer_payments.view')
            && ($user->can('customers.view_debts') || $payment->user_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->can('customer_payments.create');
    }

    public function update(User $user, CustomerPayment $payment): bool
    {
        return false;
    }

    public function delete(User $user, CustomerPayment $payment): bool
    {
        return false;
    }
}
