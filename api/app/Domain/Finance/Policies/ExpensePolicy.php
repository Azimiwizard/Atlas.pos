<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\Expense;
use App\Models\User;
use App\Policies\BasePolicy;

class ExpensePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $this->isManager($user);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->sameTenant($user, $expense) && $this->canAccessStore($user, $expense->store_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->view($user, $expense);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->view($user, $expense);
    }

    protected function sameTenant(User $user, Expense $expense): bool
    {
        return $expense->tenant_id === $user->tenant_id;
    }

    protected function canAccessStore(User $user, ?string $storeId): bool
    {
        if ($user->store_id === null || $storeId === null) {
            return true;
        }

        return $user->store_id === $storeId;
    }
}
