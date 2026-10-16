<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityAccessAudit;
use App\Models\CommunityWrongNumberReport;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;

final class WrongNumberReports
{
    public function __construct(private readonly PhoneVerification $phones, private readonly MemberAccess $access, private readonly MemberIdentity $identity) {}

    public function report(Tenant $tenant, Session $session, string $note, ?Member $member = null): CommunityWrongNumberReport
    {
        if ($member !== null && ($member->tenant_id !== $tenant->id || ! $this->access->canView($session, $member))) {
            throw new AuthorizationException;
        }
        $phone = $member === null ? $this->phones->verifiedPhone($session) : $this->identity->normalisePhone($member->phone);
        if ($phone === null) {
            throw new AuthorizationException('Verify the number or open your own membership first.');
        }
        abort_if(mb_trim($note) === '' || mb_strlen($note) > 500, 422);
        $connection = (new CommunityWrongNumberReport)->getConnection();

        return $connection->transaction(function () use ($connection, $tenant, $phone, $note): CommunityWrongNumberReport {
            $hash = PhoneVerification::digest($phone);
            $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['wrong-number:'.$tenant->id.':'.$hash]);
            $query = CommunityWrongNumberReport::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('phone_hash', $hash);
            $pending = (clone $query)->where('status', 'pending')->first();
            if ($pending !== null) {
                return $pending;
            }
            abort_if((clone $query)->where('created_at', '>', now()->subDay())->count() >= 3, 429);

            return CommunityWrongNumberReport::query()->create(['tenant_id' => $tenant->id, 'phone' => $phone, 'phone_hash' => $hash, 'note' => mb_trim($note), 'status' => 'pending']);
        });
    }

    public function review(CommunityWrongNumberReport $report, User $actor, bool $confirmed, string $reason): void
    {
        $this->access->authorizeStaff($report->tenant, $actor);
        abort_if(mb_strlen(mb_trim($reason)) < 10 || mb_strlen($reason) > 1000, 422);
        $report->getConnection()->transaction(function () use ($report, $actor, $confirmed, $reason): void {
            $report->getConnection()->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['wrong-number:'.$report->tenant_id.':'.$report->phone_hash]);
            $locked = CommunityWrongNumberReport::withoutGlobalScope(TenantScope::class)->where('tenant_id', $report->tenant_id)->lockForUpdate()->findOrFail($report->id);
            abort_unless($locked->status === 'pending', 410);
            $locked->update(['status' => $confirmed ? 'confirmed' : 'dismissed', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'reason' => $reason]);
            if ($confirmed) {
                app(MessagingPreferences::class)->disableMarketing($locked->tenant, 'sms', $locked->phone);
                foreach (Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $locked->tenant_id)->cursor() as $member) {
                    if ($this->identity->normalisePhone($member->phone) === $locked->phone) {
                        $this->access->revoke($member, $actor, $reason);
                    }
                }
                \App\Models\CommunityAccessCredential::withoutGlobalScope(TenantScope::class)->where('tenant_id', $locked->tenant_id)->where('phone_hash', $locked->phone_hash)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }
            CommunityAccessAudit::query()->create(['tenant_id' => $locked->tenant_id, 'actor_id' => $actor->id, 'action' => $confirmed ? 'wrong_number_confirmed' : 'wrong_number_dismissed', 'reason' => $reason]);
        });
    }

    public function resolve(CommunityWrongNumberReport $report, User $actor, string $method, string $reason): void
    {
        $this->access->authorizeStaff($report->tenant, $actor);
        abort_unless(in_array($method, ['in_person', 'known_contact'], true) && mb_strlen(mb_trim($reason)) >= 10 && mb_strlen($reason) <= 1000, 422);
        $report->getConnection()->transaction(function () use ($report, $actor, $method, $reason): void {
            $report->getConnection()->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['wrong-number:'.$report->tenant_id.':'.$report->phone_hash]);
            $locked = CommunityWrongNumberReport::withoutGlobalScope(TenantScope::class)->where('tenant_id', $report->tenant_id)->lockForUpdate()->findOrFail($report->id);
            abort_unless($locked->status === 'confirmed', 410);
            CommunityWrongNumberReport::withoutGlobalScope(TenantScope::class)->where('tenant_id', $locked->tenant_id)->where('phone_hash', $locked->phone_hash)->where('status', 'confirmed')->update(['status' => 'resolved', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'reason' => $reason]);
            CommunityAccessAudit::query()->create(['tenant_id' => $locked->tenant_id, 'actor_id' => $actor->id, 'action' => 'number_reconfirmed', 'method' => $method, 'reason' => $reason]);
        });
    }

    public function isBlocked(Tenant $tenant, string $channel, string $address): bool
    {
        $phone = $channel === 'sms' ? $this->identity->normalisePhone($address) : null;

        return $phone !== null && CommunityWrongNumberReport::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)
            ->where('phone_hash', PhoneVerification::digest($phone))->where('status', 'confirmed')->exists();
    }
}
