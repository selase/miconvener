<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Artisan::call('migrate', [
        '--path' => 'database/migrations/landlord',
        '--realpath' => true,
    ]);
});

test('landlord seeder creates sample tenant and user membership', function () {
    Artisan::call('db:seed', ['--class' => 'LandlordSeeder']);

    expect(Tenant::count())->toBeGreaterThan(0);
    expect(User::count())->toBeGreaterThan(0);

    $tenant = Tenant::first();
    $user = User::first();

    expect(DB::connection('landlord')->table('tenant_user')
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->exists())->toBeTrue();
});
