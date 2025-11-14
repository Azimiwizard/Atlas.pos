<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->unique()->words(mt_rand(2, 4), true),
            'sku' => $this->faker->unique()->bothify('SKU-#####'),
            'barcode' => $this->faker->boolean(60) ? $this->faker->unique()->ean13() : null,
            'price' => $this->faker->randomFloat(2, 1, 200),
            'tax_code' => $this->faker->boolean(40) ? $this->faker->bothify('TAX-##') : null,
            'track_stock' => $this->faker->boolean(80),
            'image_url' => null,
            'is_active' => true,
        ];
    }
}
