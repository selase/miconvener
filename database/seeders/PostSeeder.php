<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant\Post;
use Illuminate\Database\Seeder;

final class PostSeeder extends Seeder
{
    public function run(): void
    {
        Post::create([
            'title' => 'Seed Post 1',
            'content' => 'Content for seed post 1',
        ]);

        Post::create([
            'title' => 'Seed Post 2',
            'content' => 'Content for seed post 2',
        ]);
    }
}
