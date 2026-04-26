<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by'   => User::factory(),
            'name'         => $this->faker->words(3, true),
            'description'  => $this->faker->sentence(),
            'color'        => $this->faker->hexColor(),
            'status'       => 'active',
        ];
    }
}
