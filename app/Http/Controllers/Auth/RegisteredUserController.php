<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enum\TenantStatusEnum;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
        ]);

        // Ensure the selected plan is not the free plan
        /** @var Package $package */
        $package = Package::where('slug', $request->input('plan'))->firstOrFail();

        if ($package->isFree()) {
            throw ValidationException::withMessages([
                'plan' => 'Please select a paid plan to continue.',
            ]);
        }

        // Create user
        $user = User::query()->create([
            'first_name' => $request->string('first_name')->toString(),
            'last_name' => $request->string('last_name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => Hash::make($request->string('password')->toString()),
        ]);

        event(new Registered($user));

        // Create tenant (organization)
        $slug = $this->uniqueSlug($request->string('organization_name')->toString());

        $tenant = Tenant::query()->create([
            'name' => $request->string('organization_name')->toString(),
            'slug' => $slug,
            'email' => $request->string('email')->toString(),
            'status' => TenantStatusEnum::ACTIVE,
            'isolation_mode' => 'shared',
            'db_driver' => 'pgsql',
        ]);

        // Provision tenant database and run migrations
        $this->provisioner->provision($tenant);

        // Attach user to tenant
        $user->tenants()->attach($tenant->id);

        // Login and set active tenant in session
        Auth::login($user);

        session(['active_tenant_id' => $tenant->id]);

        return redirect()->route('billing.confirm', [
            'plan' => $package->slug,
            'interval' => 'month',
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
