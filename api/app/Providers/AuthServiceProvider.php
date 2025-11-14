<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Policies\ExpensePolicy;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Register;
use App\Models\Shift;
use App\Models\Tax;
use App\Policies\CategoryPolicy;
use App\Policies\OrderPolicy;
use App\Policies\ProductPolicy;
use App\Policies\PromotionPolicy;
use App\Policies\RegisterPolicy;
use App\Policies\ShiftPolicy;
use App\Policies\TaxPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Product::class => ProductPolicy::class,
        Category::class => CategoryPolicy::class,
        Tax::class => TaxPolicy::class,
        Promotion::class => PromotionPolicy::class,
        Register::class => RegisterPolicy::class,
        Shift::class => ShiftPolicy::class,
        Order::class => OrderPolicy::class,
        Expense::class => ExpensePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        Gate::before(function ($user, $ability) {
            return $user->role === UserRole::ADMIN ? true : null;
        });
    }
}
