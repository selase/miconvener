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

        // Create user
        $user = User::query()->create([
            'first_name' => $request->string('first_name')->toString(),
            'last_name' => $request->string('last_name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => Hash::make($request->string('password')->toString()),
        ]);

        event(new Registered($user));

        // Create tenant (organization)
        $tenant = Tenant::query()->create([
            'name' => $request->string('organization_name')->toString(),
            'slug' => $request->string('slug')->toString(),
            'email' => $request->string('email')->toString(),
            'status' => TenantStatusEnum::ACTIVE,
            'isolation_mode' => 'shared',
            'db_driver' => 'pgsql',
            // The free plan is granted here because nothing downstream will grant
            // it: a free signup never reaches the payment callback that assigns a
            // package on the paid path.
            'package_id' => $package->isFree() ? $package->id : null,
        ]);

        // Provision tenant database and run migrations
        $this->provisioner->provision($tenant);

        // Attach user to tenant
        $user->tenants()->attach($tenant->id);

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
