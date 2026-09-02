<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\getJson;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

test('users can generate api tokens', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test-token')->plainTextToken;
    expect($token)->toBeString();
});

test('users can access protected routes with api token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('access-token')->plainTextToken;

    $response = getJson('/api/user', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertStatus(200);
});
