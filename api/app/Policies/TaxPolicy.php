<?php

namespace App\Policies;

use App\Models\Tax;
use App\Models\User;

class TaxPolicy extends BasePolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, Tax $tax): bool { return $tax->tenant_id === $user->tenant_id; }
    public function create(User $user): bool { return $this->isManager($user) || $this->isAdmin($user); }
    public function update(User $user, Tax $tax): bool { return ($this->isManager($user) || $this->isAdmin($user)) && $tax->tenant_id === $user->tenant_id; }
}

