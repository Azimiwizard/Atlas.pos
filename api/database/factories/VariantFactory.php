<?php

namespace Database\Factories;

use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variant>
 */
class VariantFactory extends Factory
{
    protected $model = Variant::class;

    public function definition(): array
    {
        return [
            'sku' => $this->faker->boolean(60) ? strtoupper($this->faker->unique()->bothify('SKU-####')) : null,
            'name' => $this->faker->boolean(40) ? ucfirst($this->faker->safeColorName()) : null,
            'price' => $this->faker->randomFloat(2, 2.5, 49.99),
            'cost' => $this->faker->boolean(60) ? $this->faker->randomFloat(2, 1.5, 35) : null,
            'track_stock' => true,
            'barcode' => $this->faker->boolean(40) ? $this->faker->unique()->ean13() : null,
            'is_default' => false,
        ];
    }
}
