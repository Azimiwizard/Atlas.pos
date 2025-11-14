<?php

namespace App\Policies;

use App\Models\Promotion;
use App\Models\User;

class PromotionPolicy extends BasePolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, Promotion $promotion): bool { return $promotion->tenant_id === $user->tenant_id; }
    public function create(User $user): bool { return $this->isManager($user) || $this->isAdmin($user); }
    public function update(User $user, Promotion $promotion): bool { return ($this->isManager($user) || $this->isAdmin($user)) && $promotion->tenant_id === $user->tenant_id; }
}

