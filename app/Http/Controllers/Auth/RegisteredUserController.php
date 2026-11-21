<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enum\TenantStatusEnum;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\TenantHandle;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class RegisteredUserController extends Controller
{
    public function __construct(private readonly TenantProvisioner $provisioner) {}

    /**
     * Display the registration view.
     */
    public function create(): Factory|View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): Redirector|RedirectResponse
    {
        $email = $request->input('email');
        if (is_string($email)) {
            $request->merge(['email' => mb_strtolower(mb_trim($email))]);
        }
        $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'organization_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'plan' => ['required', 'string', 'exists:packages,slug'],
            'slug' => TenantHandle::rules(),
        ], TenantHandle::messages());

        /** @var Package $package */
        $package = Package::where('slug', $request->input('plan'))->firstOrFail();

        // Enterprise is priced per organisation by us, never bought at signup:
        // the organisation starts on Free until we agree a price.
        if ($package->isNegotiated()) {
            $package = Package::query()->where('is_free', true)->orderBy('sort_order')->firstOrFail();
        }

        // Create user
        $user = User::query()->create([
            'first_name' => $request->string('first_name')->toString(),
            'last_name' => $request->string('last_name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => Hash::make($request->string('password')->toString()),
            // Without this the owner of every self-registered organization was
            // left with a null status, which reads as "not active" -- so the
            // guard that refuses to remove the final active Org Superadmin
            // never fired for exactly the person it exists to protect.
            'status' => User::STATUS_ACTIVE,
        ]);

        // Create tenant (organization)
        $tenant = Tenant::query()->create([
            'name' => $request->string('organization_name')->toString(),
            'slug' => $request->string('slug')->toString(),
            'email' => $request->string('email')->toString(),
            'status' => TenantStatusEnum::DEACTIVATED,
            'isolation_mode' => 'shared',
            'db_driver' => 'pgsql',
            // The free plan is granted here because nothing downstream will grant
            // it: a free signup never reaches the payment callback that assigns a
            // package on the paid path.
            'package_id' => $package->isFree() ? $package->id : null,
            // Settled in USD when the visitor signs up from outside Ghana, and kept
            // so; a Ghana visitor's region stays open until evidence of Ghana exists.
            'pricing_region' => app(\App\Services\Pricing\VisitorCurrency::class)->forVisitor($request) === 'USD' ? \App\Services\Pricing\GhanaEligibility::REGION_INTERNATIONAL : null,
        ]);

        // Provision tenant database and run migrations
        $this->provisioner->provision($tenant);

        \Illuminate\Support\Facades\DB::connection('landlord')->transaction(function () use ($tenant, &$user): void {
            $lockedTenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($user->status, [null, User::STATUS_ACTIVE], true), 403);
            $role = \App\Models\Role::query()->where('name', 'Org Superadmin')->whereNull('tenant_id')->firstOrFail();
            $user->forceFill(['tenant_id' => $tenant->id])->save();
            $user->allTenants()->attach($tenant->id, ['accepted_at' => now()]);
            app(\App\Services\Tenancy\OrganisationMemberships::class)->inTenant($lockedTenant, [$user], fn () => $user->assignRole($role));
            $lockedTenant->forceFill(['status' => TenantStatusEnum::ACTIVE])->save();
            $tenant->setRawAttributes($lockedTenant->getAttributes(), true);
        });
        event(new Registered($user));
        app(\App\Services\Platform\SignupAlerts::class)->signedUp($tenant);

        // Login and set active tenant in session
        Auth::login($user);

        session(['active_tenant_id' => $tenant->id]);

        if ($package->isFree()) {
            return redirect()->route('tenant.onboarding.wizard', ['subdomain' => $tenant->slug]);
        }

        return redirect()->route('billing.confirm', [
            'plan' => $package->slug,
            'interval' => 'month',
        ]);
    }
}
