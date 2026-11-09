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
use InvalidArgumentException;

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
        abort_unless($fresh->status === 'active' && $fresh->two_factor_confirmed_at !== null && filled($fresh->two_factor_secret), 403, 'Active staff with confirmed two-factor authentication are required.');
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
        abort_unless($fresh->status === 'active' && $fresh->two_factor_confirmed_at !== null && filled($fresh->two_factor_secret), 403);
        abort_unless(DB::connection('landlord')->table('model_has_roles')->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->whereNull('roles.tenant_id')->where('model_has_roles.model_id', $fresh->id)
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
                'requested_by' => $actor->id, 'destination_display' => mb_substr(($account->resolved_account_name ?? $account->account_name).' · '.$account->label.' · '.$account->maskedAccountNumber(), 0, 250), 'requester_security_fingerprint' => $this->securityFingerprint($actor), 'terms_fingerprint' => $this->termsFingerprint($tenant, $data['category']), 'account_fingerprint' => $this->accountFingerprint(), 'recipient_fingerprint' => $this->recipientFingerprint($account)]);
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
            $current->update(['status' => 'approved', 'approved_by' => $actor->id, 'approver_security_fingerprint' => $this->securityFingerprint($actor), 'approved_at' => now(), 'active_after' => now()->addDay()]);
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

    public function pausePlatform(OrganisationPayoutPolicy $policy, User $actor): void
    {
        $this->authorisePlatform($actor);
        $policy->getConnection()->transaction(function () use ($policy, $actor): void {
            app(CommerceInventoryLocks::class)->tenant($policy->tenant_id);
            $this->pauseLocked(OrganisationPayoutPolicy::query()->lockForUpdate()->findOrFail($policy->id), $actor);
        });
    }

    public function assertDestination(OrganisationPayoutPolicy $policy): TenantPayoutAccount
    {
        $tenant = Tenant::query()->findOrFail($policy->tenant_id);
        $account = TenantPayoutAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->find($policy->payout_account_id);
        if ($tenant->status !== \App\Enum\TenantStatusEnum::ACTIVE || ! $tenant->isPlatformDefaultSettlement() || $account === null || ! $account->is_verified
            || ! hash_equals($policy->account_fingerprint, $this->accountFingerprint()) || ! hash_equals($policy->recipient_fingerprint, $this->recipientFingerprint($account))) {
            $this->refuse('The original settlement account or verified destination changed. Request a new policy.');
        }

        return $account;
    }

    public function assertActive(OrganisationPayoutPolicy $policy): void
    {
        if (config('services.payout_schedules.paused') === true || $policy->status !== 'approved' || $policy->active_after === null || now()->lt($policy->active_after)) {
            $this->refuse('This policy is paused, unapproved or still in its cooling period.');
        }
        $this->assertDestination($policy);
        $requester = User::query()->findOrFail($policy->requested_by);
        $approver = User::query()->findOrFail($policy->approved_by);
        try {
            $this->authoriseTenant(Tenant::query()->findOrFail($policy->tenant_id), $requester);
            $this->authorisePlatform($approver);
        } catch (\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpException) {
            $this->refuse('The policy authority changed. Request fresh independent approval.');
        }
        if (! hash_equals($policy->requester_security_fingerprint, $this->securityFingerprint($requester))
            || ! hash_equals($policy->approver_security_fingerprint ?? '', $this->securityFingerprint($approver))) {
            $this->refuse('Credentials, contacts or permissions changed. Request fresh independent approval.');
        }
        if (! hash_equals($policy->terms_fingerprint, $this->termsFingerprint(Tenant::query()->findOrFail($policy->tenant_id), $policy->category))) {
            $this->refuse('Commercial terms changed. Request a new approved policy.');
        }
    }

    public function termsFingerprint(Tenant $tenant, string $category, ?\App\Models\Event $event = null): string
    {
        if ($category === 'commerce') {
            $terms = \App\Models\StoreCommercialAgreement::query()->where('tenant_id', $tenant->id)->where('approved_at', '<=', now())->orderBy('version')->get()->toArray();
        } elseif ($event !== null) {
            $resolver = app(PlatformFeeResolver::class);
            $terms = [$resolver->percentageFor($event), $resolver->capAmountFor($event), $resolver->bearerFor($event),
                $event->payoutSchedule?->only(['status', 'schedule_type', 'days_after_event', 'holdback_percentage', 'holdback_release_days'])];
        } else {
            $stream = match ($category) {
                'event' => \App\Enum\CommercialStreamEnum::Tickets,
                'dues' => \App\Enum\CommercialStreamEnum::Dues,
                'giving' => \App\Enum\CommercialStreamEnum::Giving,
                default => throw new InvalidArgumentException('Unsupported payout category.'),
            };
            $terms = app(CommercialTerms::class)->for($tenant, $stream);
        }

        return hash('sha256', json_encode($terms, JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $evidence */
    public function record(OrganisationPayoutPolicy $policy, ?User $actor, string $action, array $evidence = []): void
    {
        PayoutPolicyAction::query()->create(['policy_id' => $policy->id, 'actor_id' => $actor?->id, 'action' => $action,
            'evidence' => $evidence, 'created_at' => now()]);
        app(ScheduledPayoutNotices::class)->enqueue($policy, $action, (string) ($evidence['proposal_id'] ?? $policy->id));
    }

    private function securityFingerprint(User $actor): string
    {
        $fresh = User::query()->findOrFail($actor->id);
        $roles = DB::connection('landlord')->table('model_has_roles')->where('model_id', $fresh->id)->where('model_type', $fresh::class)->orderBy('role_id')->orderBy('tenant_id')->get()->toArray();
        $roleIds = array_map(fn ($role) => $role->role_id, $roles);
        $permissions = DB::connection('landlord')->table('role_has_permissions')->whereIn('role_id', $roleIds)->orderBy('role_id')->orderBy('permission_id')->get()->toArray();
        $direct = DB::connection('landlord')->table('model_has_permissions')->where('model_id', $fresh->id)->where('model_type', $fresh::class)->orderBy('permission_id')->orderBy('tenant_id')->get()->toArray();

        return hash('sha256', json_encode([$fresh->id, $fresh->email, $fresh->getAuthPassword(), $fresh->two_factor_secret,
            $fresh->two_factor_confirmed_at?->toIso8601String(), $roles, $permissions, $direct], JSON_THROW_ON_ERROR));
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
