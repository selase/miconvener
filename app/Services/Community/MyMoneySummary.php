<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\Member;
use App\Services\Events\PlatformAttendeeHistory;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Contracts\Session\Session;

final class MyMoneySummary
{
    public function __construct(private readonly CommunityHistory $history, private readonly MemberAccess $access, private readonly PlatformAttendeeVerification $emails, private readonly PlatformAttendeeHistory $events) {}

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function for(Session $session, array $filters): array
    {
        $members = $this->history->moneyMembers($session, $filters);
        $members->through(function (Member $member) use ($session, $filters): array {
            $currencies = $member->charges->when(! empty($filters['currency']), fn ($rows) => $rows->where('currency', $filters['currency']))->map(fn ($charge): array => ['currency' => $charge->currency, 'open_total' => (int) $charge->getAttribute('open_total')])->values()->all();
            if ($currencies === []) {
                $currencies = [['currency' => $filters['currency'] ?? $member->membershipType->currency, 'open_total' => 0]];
            }

            return ['tenant_slug' => $member->tenant->slug, 'organisation' => $member->tenant->name, 'member_id' => $member->id, 'member_name' => $member->name, 'access_role' => $this->access->role($session, $member), 'status' => $member->status->value, 'totals_by_currency' => $currencies,
                'pay_url' => route('public.dues.show', ['subdomain' => $member->tenant->slug, 'token' => $member->pay_token]),
                'charges_url' => route('attendee.my.communities.charges', ['tenantSlug' => $member->tenant->slug, 'member' => $member->id]),
                'statement_url' => route('attendee.my.communities.statement', ['tenantSlug' => $member->tenant->slug, 'member' => $member->id])];
        });
        $email = $this->emails->verifiedEmail($session);

        return ['dues' => $members->toArray(), 'totals_by_currency' => $this->history->moneyTotals($session, $filters), 'contributions' => $email === null ? null : $this->events->getAppContributions($email, $filters)->toArray()];
    }
}
