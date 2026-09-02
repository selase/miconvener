<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Config::set('database.connections.tenant', Config::get('database.connections.landlord'));
    DB::connection('tenant')->setPdo(DB::connection('landlord')->getPdo());

    Artisan::call('migrate', [
        '--database' => 'tenant',
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
    ]);
});

// ── Basic registration ──────────────────────────────────────────────────────

test('user can register and receives a token', function () {
    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'device_name' => 'iPhone 15',
    ])->assertStatus(201)
        ->assertJsonStructure(['token', 'user'])
        ->assertJsonPath('user.email', 'jane@example.com')
        ->assertJsonPath('user.first_name', 'Jane');
});

test('registered user has no tenant in profile', function () {
    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'No',
        'last_name' => 'Tenant',
        'email' => 'notenant@example.com',
        'password' => 'password123',
        'device_name' => 'TestDevice',
    ])->assertStatus(201)
        ->assertJsonPath('user.tenant', null);
});

test('user is created in the database after registration', function () {
    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Created',
        'last_name' => 'User',
        'email' => 'created@example.com',
        'password' => 'password123',
        'device_name' => 'TestDevice',
    ])->assertStatus(201);

    $this->assertDatabaseHas('users', ['email' => 'created@example.com']);
});

// ── Validation ──────────────────────────────────────────────────────────────

test('registration requires all fields', function () {
    $this->postJson('/api/v1/auth/register', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'password', 'device_name']);
});

test('registration rejects duplicate email', function () {
    User::factory()->create(['email' => 'duplicate@example.com']);

    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Dupe',
        'last_name' => 'User',
        'email' => 'duplicate@example.com',
        'password' => 'password123',
        'device_name' => 'TestDevice',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('registration rejects password shorter than 8 characters', function () {
    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Short',
        'last_name' => 'Pass',
        'email' => 'shortpass@example.com',
        'password' => 'abc',
        'device_name' => 'TestDevice',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});
