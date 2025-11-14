<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy extends BasePolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, Category $category): bool { return $category->tenant_id === $user->tenant_id; }
    public function create(User $user): bool { return $this->isManager($user) || $this->isAdmin($user); }
    public function update(User $user, Category $category): bool { return ($this->isManager($user) || $this->isAdmin($user)) && $category->tenant_id === $user->tenant_id; }
}

