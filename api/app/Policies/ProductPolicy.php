<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return true; // any authenticated
    }

    public function view(User $user, Product $product): bool
    {
        return $product->tenant_id === $user->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->isManager($user) || $this->isAdmin($user);
    }

    public function update(User $user, Product $product): bool
    {
        return ($this->isManager($user) || $this->isAdmin($user)) && $product->tenant_id === $user->tenant_id;
    }

    public function delete(User $user, Product $product): bool
    {
        return ($this->isManager($user) || $this->isAdmin($user)) && $product->tenant_id === $user->tenant_id;
    }
}

