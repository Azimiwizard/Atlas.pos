<?php

namespace Database\Factories;

use App\Domain\Finance\Models\Expense;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Domain\Finance\Models\Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'store_id' => null,
            'category' => $this->faker->randomElement(['Facilities', 'Supplies', 'Payroll', 'Marketing']),
            'amount' => $this->faker->randomFloat(2, 10, 1000),
            'incurred_at' => $this->faker->dateTimeBetween('-2 months'),
            'vendor' => $this->faker->company(),
            'notes' => $this->faker->optional()->sentence(),
            'created_by' => null,
        ];
    }
}
