<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Post;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

final class PostFactory extends Factory
{
    protected $model = Post::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'title' => $this->faker->sentence(),
            'content' => $this->faker->paragraph(),
        ];
    }
}
