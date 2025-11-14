<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy extends BasePolicy
{
    public function view(User $user, Order $order): bool { return $order->tenant_id === $user->tenant_id; }
    public function createDraft(User $user): bool { return $this->isCashier($user) || $this->isManager($user) || $this->isAdmin($user); }
    public function addItem(User $user, Order $order): bool { return $this->view($user, $order) && ($this->isCashier($user) || $this->isManager($user) || $this->isAdmin($user)); }
    public function capture(User $user, Order $order): bool { return $this->view($user, $order) && ($this->isCashier($user) || $this->isManager($user) || $this->isAdmin($user)); }
    public function refund(User $user, Order $order): bool { return $this->view($user, $order) && ($this->isManager($user) || $this->isAdmin($user)); }
}

