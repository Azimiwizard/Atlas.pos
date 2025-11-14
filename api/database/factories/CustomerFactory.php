<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        $name = $this->faker->name();
        $email = $this->faker->unique()->safeEmail();

        return [
            'name' => $name,
            'phone' => $this->faker->unique()->numerify('555-###-####'),
            'email' => $email,
            'loyalty_points' => $this->faker->numberBetween(0, 150),
            'notes' => $this->faker->boolean(30) ? $this->faker->sentence() : null,
        ];
    }
}

