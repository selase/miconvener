<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Contracts\SmsGateway;
use App\Mail\CommunityContactNotice as NoticeMail;
use App\Models\CommunityContactNotice;
use App\Models\Tenant;
use App\Models\TenantNotificationSetting;
use App\Scopes\TenantScope;
use App\Services\Sms\SmsMessage;
use App\Services\Sms\SmsResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class ContactNotices
{
    public function __construct(private readonly SmsGateway $gateway, private readonly CommunitySmsBudget $budget, private readonly WrongNumberReports $reports) {}

    public function deliver(string $id): void
    {
        $notice = (new CommunityContactNotice)->getConnection()->transaction(function () use ($id): ?CommunityContactNotice {
            $notice = CommunityContactNotice::withoutGlobalScope(TenantScope::class)->lockForUpdate()->find($id);
            if ($notice === null || $notice->status !== 'pending') {
                return null;
            }
            $notice->update(['status' => 'submitting', 'submitted_at' => now()]);

            return $notice;
        });
        if ($notice === null) {
            return;
        }
        $tenant = Tenant::query()->find($notice->tenant_id);
        if ($tenant === null) {
            return;
        }
        if ($notice->channel === 'email') {
            try {
                Mail::to($notice->address)->send(new NoticeMail($tenant->name));
                $notice->update(['status' => 'sent']);
            } catch (Throwable) {
                $notice->update(['status' => 'unknown', 'reason' => 'outcome_unknown']);
            }

            return;
        }
        if (! TenantNotificationSetting::forTenant($tenant->id)->sms_enabled || ! $this->gateway->isConfigured() || $this->reports->isBlocked($tenant, 'sms', $notice->address)) {
            $notice->update(['status' => 'skipped', 'reason' => 'sms_unavailable_or_disputed']);

            return;
        }
        $reservation = $this->budget->reserve($tenant, 'member_contact_notice', $notice->id, CarbonImmutable::now());
        if ($reservation === null) {
            $notice->update(['status' => 'skipped', 'reason' => 'no_credit_or_monthly_limit']);

            return;
        }
        try {
            $results = $this->gateway->send([new SmsMessage($notice->address, 'Contact details changed at MiConvener. If this was unexpected, contact your organisation. No action is needed if you requested the change.', $notice->id)]);
            $result = $results[$notice->id] ?? SmsResult::unknown('No transport result.');
        } catch (Throwable) {
            $result = SmsResult::unknown('Transport outcome unknown.');
        }
        $this->budget->finish($tenant, $reservation->id, $result);
        $notice->update(['status' => $result->accepted ? 'sent' : ($result->uncertain ? 'unknown' : 'failed'), 'reason' => $result->accepted ? null : ($result->uncertain ? 'outcome_unknown' : 'provider_refused')]);
    }
}
