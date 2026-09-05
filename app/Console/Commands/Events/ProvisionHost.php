<?php

declare(strict_types=1);

namespace App\Console\Commands\Events;

use App\Enum\TenantStatusEnum;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

final class ProvisionHost extends Command
{
    protected $signature = 'events:provision-host {slug} {email} {--password=}';

    protected $description = 'One-off: provision a tenant + host user for the event registration MVP.';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $email = $this->argument('email');
        $password = $this->option('password') ?: str()->random(16);

        $tenant = Tenant::firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'email' => $email, 'status' => TenantStatusEnum::ACTIVE]
        );

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'first_name' => 'Host',
                'last_name' => ucfirst($slug),
                'password' => Hash::make($password),
            ]
        );
        $user->forceFill(['email_verified_at' => now()])->save();

        $user->tenant_id = $tenant->id;
        $user->save();

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            $user->tenants()->attach($tenant->id);
        }

        setPermissionsTeamId($tenant->id);
        if (! $user->hasRole('Org Superadmin')) {
            $user->assignRole('Org Superadmin');
        }

        TenantFeature::firstOrCreate(
            ['tenant_id' => $tenant->id, 'feature_key' => 'commerce'],
            ['enabled' => true]
        );

        $this->info("Tenant: {$tenant->slug} ({$tenant->id})");
        $this->info("User: {$user->email}");
        $this->info("Password: {$password}");
        $this->info('Roles: '.$user->roles->pluck('name')->implode(', '));
        $this->info('Commerce enabled: '.($tenant->fresh()->featureEnabled('commerce') ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
