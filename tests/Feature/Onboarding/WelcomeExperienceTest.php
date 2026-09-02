<?php

declare(strict_types=1);

use App\Enum\TenantStatusEnum;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->seed(Database\Seeders\PermissionsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('Org Admin');

    $this->tenant = setActiveTenantForTest($this->user, [
        'status' => TenantStatusEnum::ACTIVE,
        'onboarding_completed_at' => null,
    ]);
});

test('it can visit the welcome page', function () {
    $this->actingAs($this->user);

    $response = $this->get('/onboarding/wizard');

    $response->assertStatus(200);
    $response->assertSee('Welcome to '.$this->tenant->name);
});

test('it can update branding and redirect to dashboard', function () {
    Storage::fake('public');
    $this->actingAs($this->user);

    $response = $this->post('/onboarding/branding', [
        'name' => 'New Org Name',
        'logo' => UploadedFile::fake()->image('logo.png'),
    ]);

    $response->assertRedirect('/dashboard');
    $response->assertSessionHas('onboarding_just_completed', true);

    $this->tenant->refresh();
    expect($this->tenant->name)->toBe('New Org Name');
    expect($this->tenant->logo)->not->toBeNull();
});

test('it can skip to dashboard and mark as finished', function () {
    $this->actingAs($this->user);

    $response = $this->post('/onboarding/finish');

    $response->assertRedirect('/dashboard');
    $this->tenant->refresh();
    expect($this->tenant->onboarding_completed_at)->not->toBeNull();
});
