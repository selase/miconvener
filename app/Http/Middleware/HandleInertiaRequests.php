<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $tenant = app(TenantContext::class)->getTenant();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                // Hides owner-only navigation and actions; every route still
                // checks the permission itself.
                'can' => [
                    'manage_billing' => fn (): bool => (bool) $request->user()?->can('manage billing'),
                ],
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'features' => [
                    'paid_tickets' => $tenant->planAllows('paid_tickets'),
                    // Gates the Finance link: selling paid tickets now, or money
                    // from tickets sold before a lapse still to refund or pay out.
                    'finance' => $tenant->handlesTicketMoney(),
                    'llm_byok' => $tenant->featureEnabled('llm_byok'),
                ],
            ] : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'plainKey' => fn () => $request->session()->get('plainKey'),
            ],
        ];
    }
}
