<?php

namespace App\Policies;

use App\Models\Register;
use App\Models\User;

class RegisterPolicy extends BasePolicy
{
    public function viewAny(User $user): bool { return $this->isCashier($user) || $this->isManager($user) || $this->isAdmin($user); }
    public function view(User $user, Register $register): bool { return $register->tenant_id === $user->tenant_id; }
    public function create(User $user): bool { return $this->isManager($user) || $this->isAdmin($user); }
    public function update(User $user, Register $register): bool { return ($this->isManager($user) || $this->isAdmin($user)) && $register->tenant_id === $user->tenant_id; }
}

