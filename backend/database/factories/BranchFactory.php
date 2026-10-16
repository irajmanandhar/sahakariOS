<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Cooperative;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Synthetic test data only. @extends Factory<Branch> */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cooperative_id' => Cooperative::factory(),
            'code' => 'BR-'.fake()->unique()->numerify('###'),
            'name' => 'Test Branch',
        ];
    }

    public function headOffice(): static
    {
        return $this->state(['code' => 'HO', 'name' => 'Head Office', 'is_head_office' => true]);
    }
}
