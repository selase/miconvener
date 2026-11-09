<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\OrganisationPayoutPolicy;
use App\Models\PayoutPolicyAction;
use App\Models\ScheduledPayoutProposal;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Models\User;
use App\Services\Giving\GivingGateways;
use App\Services\Marketplace\CommerceInventoryLocks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PayoutPolicies
{
    public function accountFingerprint(): string
    {
        if (! filled(config('services.settlement.paystack.secret_key'))) {
            $this->refuse('Platform settlement is not configured.');
        }

        return hash('sha256', app(GivingGateways::class)->platformIdentity());
    }

    public function recipientFingerprint(TenantPayoutAccount $account): string
    {
        return hash('sha256', implode('|', [$account->tenant_id, $account->id, $account->type, $account->bank_code,
            $account->account_number_encrypted, $account->resolved_account_name ?? $account->account_name, (int) $account->is_verified]));
    }

    public function authoriseTenant(Tenant $tenant, User $actor, string $permission = 'manage payout schedules'): void
    {
        $fresh = User::query()->findOrFail($actor->id);
        abort_unless($fresh->status === 'active' && $fresh->two_factor_confirmed_at !== null, 403, 'Active staff with confirmed two-factor authentication are required.');
        abort_unless($tenant->users()->whereKey($fresh->id)->exists(), 403);
        $previous = getPermissionsTeamId();
        try {
            setPermissionsTeamId($tenant->id);
            $fresh->unsetRelation('roles')->unsetRelation('permissions');
            Gate::forUser($fresh)->authorize($permission);
        } finally {
            setPermissionsTeamId($previous);
        }
    }

    public function authorisePlatform(User $actor): void
    {
        $fresh = User::query()->findOrFail($actor->id);
        abort_unless($fresh->status === 'active' && $fresh->two_factor_confirmed_at !== null, 403);
        abort_unless(DB::connection('landlord')->table('model_has_roles')->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')->where('model_has_roles.model_id', $fresh->id)
            ->where('model_has_roles.model_type', $fresh::class)->whereNull('model_has_roles.tenant_id')
            ->where('permissions.name', 'approve payout policies')->exists(), 403);
    }

    /** @param array<string, mixed> $input */
    public function request(Tenant $tenant, User $actor, array $input): OrganisationPayoutPolicy
    {
        $this->authoriseTenant($tenant, $actor);
        $data = Validator::make($input, [
            'category' => ['required', Rule::in(['event', 'dues', 'giving', 'commerce'])], 'currency' => ['required', Rule::in(['GHS'])],
            'payout_account_id' => ['required', 'uuid'], 'timezone' => ['required', 'timezone'],
            'cadence' => ['required', Rule::in(['daily', 'weekly'])], 'weekday' => ['required_if:cadence,weekly', 'nullable', 'integer', 'between:0,6'],
            'local_time' => ['required', 'date_format:H:i'], 'minimum_amount' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'maximum_amount' => ['required', 'integer', 'gte:minimum_amount', 'max:9007199254740991'],
            'daily_limit' => ['required', 'integer', 'gte:maximum_amount', 'max:9007199254740991'],
            'rolling_limit' => ['required', 'integer', 'gte:maximum_amount', 'max:9007199254740991'],
            'reserve_amount' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
        ])->validate();

        return $tenant->getConnection()->transaction(function () use ($tenant, $actor, $data): OrganisationPayoutPolicy {
            $tenant = app(CommerceInventoryLocks::class)->tenant($tenant->id);
            $this->authoriseTenant($tenant, $actor);
            if (! $tenant->isPlatformDefaultSettlement()) {
                $this->refuse('Scheduled payouts require platform-held funds.');
            }
            $account = TenantPayoutAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->find($data['payout_account_id']);
            if ($account === null || ! $account->is_verified) {
                $this->refuse('Choose a verified payout account belonging to this organisation.');
            }
            $prior = OrganisationPayoutPolicy::query()->where('tenant_id', $tenant->id)->where('category', $data['category'])->where('currency', 'GHS');
            $version = (int) (clone $prior)->max('version') + 1;
            foreach ($prior->whereIn('status', ['pending', 'approved'])->get() as $old) {
                $this->pauseLocked($old, $actor);
            }
            $policy = OrganisationPayoutPolicy::query()->create($data + ['tenant_id' => $tenant->id, 'version' => $version, 'status' => 'pending',
                'requested_by' => $actor->id, 'account_fingerprint' => $this->accountFingerprint(), 'recipient_fingerprint' => $this->recipientFingerprint($account)]);
            $this->record($policy, $actor, 'requested');

            return $policy;
        });
    }

    public function approve(OrganisationPayoutPolicy $policy, User $actor): OrganisationPayoutPolicy
    {
        $this->authorisePlatform($actor);

        return $policy->getConnection()->transaction(function () use ($policy, $actor): OrganisationPayoutPolicy {
            $tenant = app(CommerceInventoryLocks::class)->tenant($policy->tenant_id);
            $current = OrganisationPayoutPolicy::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($policy->id);
            $this->authorisePlatform($actor);
            abort_if($current->requested_by === (int) $actor->id, 403, 'Another authorised platform approver is required.');
            if ($current->status !== 'pending') {
                $this->refuse('Only the current pending policy can be approved.');
            }
            $this->assertDestination($current);
            $current->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'active_after' => now()->addDay()]);
            $this->record($current, $actor, 'approved');

            return $current->refresh();
        });
    }

    public function pause(OrganisationPayoutPolicy $policy, User $actor): void
    {
        $tenant = Tenant::query()->findOrFail($policy->tenant_id);
        $this->authoriseTenant($tenant, $actor);
        $policy->getConnection()->transaction(function () use ($tenant, $policy, $actor): void {
            app(CommerceInventoryLocks::class)->tenant($tenant->id);
            $this->pauseLocked(OrganisationPayoutPolicy::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($policy->id), $actor);
        });
    }

    public function assertDestination(OrganisationPayoutPolicy $policy): TenantPayoutAccount
    {
        $tenant = Tenant::query()->findOrFail($policy->tenant_id);
        $account = TenantPayoutAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->find($policy->payout_account_id);
        if (! $tenant->isPlatformDefaultSettlement() || $account === null || ! $account->is_verified
            || ! hash_equals($policy->account_fingerprint, $this->accountFingerprint()) || ! hash_equals($policy->recipient_fingerprint, $this->recipientFingerprint($account))) {
            $this->refuse('The original settlement account or verified destination changed. Request a new policy.');
        }

        return $account;
    }

    public function assertActive(OrganisationPayoutPolicy $policy): void
    {
        if ($policy->status !== 'approved' || $policy->active_after === null || now()->lt($policy->active_after)) {
            $this->refuse('This policy is paused, unapproved or still in its cooling period.');
        }
        $this->assertDestination($policy);
    }

    public function record(OrganisationPayoutPolicy $policy, ?User $actor, string $action, array $evidence = []): void
    {
        PayoutPolicyAction::query()->create(['policy_id' => $policy->id, 'actor_id' => $actor?->id, 'action' => $action,
            'evidence' => $evidence, 'created_at' => now()]);
    }

    private function pauseLocked(OrganisationPayoutPolicy $policy, User $actor): void
    {
        if ($policy->status === 'paused') {
            return;
        }
        $policy->update(['status' => 'paused']);
        ScheduledPayoutProposal::query()->where('policy_id', $policy->id)->where('status', 'proposed')->update(['status' => 'superseded', 'reason' => 'Policy paused or replaced.']);
        $this->record($policy, $actor, 'paused');
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['schedule' => $message]);
    }
}
