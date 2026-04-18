<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'owner_id' => User::factory(), // User も同時に生成（リレーションを持つファクトリ）
            'name'     => $name,
            'slug'     => Str::slug($name) . '-' . Str::lower(Str::random(5)),
            'color'    => $this->faker->hexColor(),
        ];
    }
}
