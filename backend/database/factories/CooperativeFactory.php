<?php

namespace Database\Factories;

use App\Models\Cooperative;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Synthetic test data only. @extends Factory<Cooperative> */
class CooperativeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'TEST-'.fake()->unique()->numerify('####'),
            'name' => 'Test Savings and Credit Cooperative '.fake()->unique()->numerify('###'),
        ];
    }
}
