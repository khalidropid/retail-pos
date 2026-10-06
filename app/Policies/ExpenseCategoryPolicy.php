<?php

namespace App\Policies;

use App\Models\ExpenseCategory;
use App\Models\User;

class ExpenseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('expense_categories.manage');
    }

    public function view(User $user, ExpenseCategory $category): bool
    {
        return $user->can('expense_categories.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('expense_categories.manage');
    }

    public function update(User $user, ExpenseCategory $category): bool
    {
        return $user->can('expense_categories.manage');
    }

    public function delete(User $user, ExpenseCategory $category): bool
    {
        return $user->can('expense_categories.manage') && ! $category->expenses()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
