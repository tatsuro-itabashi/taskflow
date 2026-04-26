<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id'  => Project::factory(),
            'created_by'  => User::factory(),
            'assignee_id' => null,
            'title'       => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'status'      => $this->faker->randomElement(['todo', 'in_progress', 'in_review', 'done']),
            'priority'    => $this->faker->randomElement(['low', 'medium', 'high', 'urgent']),
            'due_date'    => $this->faker->optional()->dateTimeBetween('now', '+30 days'),
            'position'    => $this->faker->numberBetween(1, 100),
        ];
    }
}
