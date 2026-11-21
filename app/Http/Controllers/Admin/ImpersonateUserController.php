<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class ImpersonateUserController extends Controller
{
    public function impersonate(string $id)
    {
        $this->authorize('impersonate user');

        $user = User::query()->findOrFail($id);
        $impersonator = Auth::user();
        abort_unless($impersonator->isGlobalSuperAdmin(), 403);
        abort_if(session()->has('original_user'), 403, 'Stop the current impersonation first.');
        abort_unless(in_array($user->status, [null, User::STATUS_ACTIVE], true), 403);

        // Cannot impersonate yourself
        if ($user->id === $impersonator->id) {
            return back()->with([
                'status' => 'warning',
                'message' => 'You cannot impersonate yourself.',
            ]);
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
        session()->put('original_active_tenant_id', session('active_tenant_id'));
        session()->put('original_security_version', (int) $impersonator->security_version);
        $targetTenantId = $user->tenants()->orderBy('tenants.id')->value('tenants.id');
        session()->put('impersonation_tenant_id', $targetTenantId);
        session()->put('active_tenant_id', $targetTenantId);
        session()->regenerate(true);
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

        $original = User::query()->findOrFail($impersonatorId);
        abort_unless($original->isGlobalSuperAdmin() && in_array($original->status, [null, User::STATUS_ACTIVE], true) && (int) $original->security_version === (int) session('original_security_version', 0), 403);
        $originalTenantId = session('original_active_tenant_id');
        session()->forget(['tenant_id', 'active_tenant_id', 'original_user', 'impersonation_tenant_id', 'original_active_tenant_id', 'original_security_version', 'google2fa']);
        auth()->login($original);
        if ($originalTenantId) {
            session()->put('active_tenant_id', $originalTenantId);
        }
        session()->regenerate(true);

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
