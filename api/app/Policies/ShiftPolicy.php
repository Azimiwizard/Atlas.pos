<?php

namespace App\Policies;

use App\Models\Shift;
use App\Models\User;

class ShiftPolicy extends BasePolicy
{
    public function viewAny(User $user): bool { return $this->isCashier($user) || $this->isManager($user) || $this->isAdmin($user); }
    public function view(User $user, Shift $shift): bool { return $shift->tenant_id === $user->tenant_id; }
    public function open(User $user): bool { return $this->isManager($user) || $this->isAdmin($user); }
    public function close(User $user, Shift $shift): bool { return ($this->isManager($user) || $this->isAdmin($user)) && $shift->tenant_id === $user->tenant_id; }
    public function cashMovement(User $user, Shift $shift): bool { return ($this->isCashier($user) || $this->isManager($user) || $this->isAdmin($user)) && $shift->tenant_id === $user->tenant_id; }
}

