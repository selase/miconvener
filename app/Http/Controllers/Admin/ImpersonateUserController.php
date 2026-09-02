<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

final class ImpersonateUserController extends Controller
{
    public function impersonate($id)
    {
        $this->authorize('impersonate user');

        $user = User::query()->findOrFail($id);
        $impersonator = Auth::user();

        // Cannot impersonate yourself
        if ($user->id === $impersonator->id) {
            return back()->with([
                'status' => 'warning',
                'message' => 'You cannot impersonate yourself.',
            ]);
        }

        // Only global superadmins can impersonate other superadmins
        if ($user->isGlobalSuperAdmin() && ! $impersonator->isGlobalSuperAdmin()) {
            return back()->with([
                'status' => 'warning',
                'message' => 'You are not authorized to impersonate a Superadmin.',
            ]);
        }

        // Non-superadmins can only impersonate users within their tenant
        if (! Gate::allows('access-superadmin-dashboard')) {
            $tenantId = app(TenantContext::class)->activeTenantId();
            if ($tenantId && $user->tenant_id !== $tenantId) {
                return back()->with([
                    'status' => 'warning',
                    'message' => 'You can only impersonate users within your organization.',
                ]);
            }
        }

        // Log the impersonation event
        activity()
            ->causedBy($impersonator)
            ->performedOn($user)
            ->withProperties([
                'impersonator_id' => $impersonator->id,
                'impersonator_email' => $impersonator->email,
                'target_email' => $user->email,
            ])
            ->log('Started impersonating user');

        session()->put('original_user', $impersonator->id);
        auth()->login($user);

        Log::info('User impersonation started', [
            'impersonator' => $impersonator->id,
            'target' => $user->id,
        ]);

        return to_route('dashboard')->with([
            'status' => 'success',
            'message' => 'You are now logged in as '.$user->first_name.' '.$user->last_name,
        ]);
    }

    public function stopImpersonation()
    {
        $impersonatorId = session()->get('original_user');

        if (! $impersonatorId) {
            return back()->with([
                'status' => 'warning',
                'message' => 'No active impersonation session found.',
            ]);
        }

        $currentUser = Auth::user();

        session()->forget('tenant_id');
        auth()->loginUsingId($impersonatorId);
        session()->forget('original_user');

        Log::info('User impersonation stopped', [
            'impersonator' => $impersonatorId,
            'was_impersonating' => $currentUser?->id,
        ]);

        return to_route('dashboard')->with([
            'status' => 'success',
            'message' => 'User impersonation has been stopped successfully.',
        ]);
    }
}
