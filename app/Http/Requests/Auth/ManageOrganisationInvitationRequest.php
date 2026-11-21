<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class ManageOrganisationInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $invitation = $this->route('invitation');
        if (! $actor || ! $actor->email_verified_at || ! $invitation instanceof \App\Models\OrganisationInvitation) {
            return false;
        }
        $tenant = $invitation->tenant;
        $context = app(\App\Services\Tenancy\TenantContext::class)->getTenant();
        if ($context && (string) $context->id !== (string) $tenant->id) {
            return false;
        }
        if (($tenant->require_2fa || ($actor->two_factor_secret && $actor->two_factor_confirmed_at)) && (! $actor->two_factor_confirmed_at || ! $this->session()->has('google2fa'))) {
            return false;
        }

        return $actor->isGlobalSuperAdmin() || app(\App\Services\Tenancy\OrganisationMemberships::class)->isActive($actor, $tenant);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}
