<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityMessage;
use App\Models\CommunityMessageDelivery;
use App\Models\CommunitySmsReservation;
use App\Models\DuesReminder;
use App\Models\EventBlastEmailClaim;
use App\Models\EventNotificationLog;
use App\Models\MessageOutcomeResolution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\EmailAllowance;
use App\Services\Sms\SmsAllowance;
use App\Services\Sms\SmsResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CommunitySmsReconciler
{
    public const array KINDS = ['sms_reservation', 'community_email', 'event_email', 'legacy_reminder'];

    public function __construct(private SmsAllowance $sms, private EmailAllowance $email, private CommunitySmsBudget $budget) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return ['outcome' => ['required', Rule::in(['confirmed_accepted', 'confirmed_not_submitted'])], 'evidence' => ['required', 'string', 'min:10', 'max:2000'], 'reason' => ['required', 'string', 'min:10', 'max:1000']];
    }

    public function resolve(User $operator, Tenant $tenant, string $reservationId, string $outcome, string $evidence, string $reason): void
    {
        $this->resolveSource($operator, $tenant, 'sms_reservation', $reservationId, $outcome, $evidence, $reason);
    }

    public function resolveSource(User $operator, Tenant $tenant, string $kind, string $sourceId, string $outcome, string $evidence, string $reason): void
    {
        Gate::forUser($operator)->authorize('access-superadmin-dashboard');
        Validator::make(compact('outcome', 'evidence', 'reason'), self::rules())->validate();
        abort_unless(in_array($kind, self::KINDS, true), 404);
        $allowance = in_array($kind, ['community_email', 'event_email'], true) ? $this->email : $this->sms;
        $allowance->withLock($tenant, function () use ($operator, $tenant, $kind, $sourceId, $outcome, $evidence, $reason): void {
            $audit = MessageOutcomeResolution::query()->where('tenant_id', $tenant->id)->where('source_kind', $kind)->where('source_id', $sourceId)->first();
            if ($audit !== null) {
                abort_unless($audit->outcome === $outcome, 409, 'This outcome was already resolved differently.');

                return;
            }
            $class = match ($kind) {
                'sms_reservation' => CommunitySmsReservation::class,
                'community_email' => CommunityMessageDelivery::class,
                'event_email' => EventBlastEmailClaim::class,
                'legacy_reminder' => DuesReminder::class,
            };
            $row = $class::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($sourceId);
            $old = $this->state($row);
            $this->assertUncertain($row, $kind);
            $accepted = $outcome === 'confirmed_accepted';
            if ($kind === 'sms_reservation') {
                $this->budget->finish($tenant, $row->id, $accepted ? SmsResult::accepted($row->provider_reference) : SmsResult::refused('Confirmed not submitted.'));
                $row->refresh();
                $this->syncSmsSource($tenant, $row, $accepted);
            } else {
                if (! $accepted && $row->credit_receipt_id !== null) {
                    ($kind === 'legacy_reminder' ? $this->sms : $this->email)->refund($tenant, $row->credit_receipt_id);
                }
                $row->update(['status' => $accepted ? ($kind === 'legacy_reminder' ? 'sent' : 'accepted') : 'failed', 'reason' => 'operator_confirmed_'.$outcome]);
                if ($kind === 'community_email') {
                    $this->refreshMessage($row->message_id);
                }
            }
            MessageOutcomeResolution::query()->create([
                'tenant_id' => $tenant->id, 'operator_reference' => 'user:'.$operator->id, 'resolved_by' => $operator->id, 'source_kind' => $kind, 'source_id' => $sourceId,
                'outcome' => $outcome, 'evidence' => $evidence, 'reason' => $reason, 'old_state' => $old, 'new_state' => $this->state($row->fresh()),
            ]);
        });
    }

    private function assertUncertain(Model $row, string $kind): void
    {
        if ($kind === 'legacy_reminder' && ($row->channel !== 'sms' || CommunitySmsReservation::query()->where('tenant_id', $row->tenant_id)->where('source_type', 'dues_reminder')->where('source_id', $row->id)->exists())) {
            throw ValidationException::withMessages(['source_id' => 'Use the linked SMS reservation rather than the legacy reminder.']);
        }
        if ($kind === 'community_email' && $row->channel !== 'email') {
            throw ValidationException::withMessages(['source_id' => 'Choose the email delivery source.']);
        }
        $uncertain = match ($kind) {
            'sms_reservation' => $row->status === 'reserved' && ($row->reason === 'outcome_unknown' || $row->reserved_at->lessThan(now()->subMinutes(2))),
            'legacy_reminder' => $row->status === 'reserved' && ($row->reason === 'outcome_unknown' || $row->created_at->lessThan(now()->subMinutes(2))),
            'community_email' => $row->status === 'unknown' || ($row->status === 'claimed' && $row->claimed_at?->lessThan(now()->subMinutes(2))),
            'event_email' => $row->status === 'unknown' || (in_array($row->status, ['reserved', 'submitting'], true) && $row->claimed_at?->lessThan(now()->subMinutes(2))),
        };
        if (! $uncertain) {
            throw ValidationException::withMessages(['source_id' => 'Only uncertain or interrupted submissions may be resolved here.']);
        }
    }

    private function syncSmsSource(Tenant $tenant, CommunitySmsReservation $row, bool $accepted): void
    {
        if ($row->source_type === 'dues_reminder') {
            DuesReminder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereKey($row->source_id)->update(['status' => $accepted ? 'sent' : 'failed', 'reason' => 'operator_confirmed']);
        } elseif ($row->source_type === 'community_message') {
            $delivery = CommunityMessageDelivery::query()->where('tenant_id', $tenant->id)->find($row->source_id);
            if ($delivery !== null) {
                $delivery->update(['status' => $accepted ? 'accepted' : 'failed', 'reason' => 'operator_confirmed']);
                $this->refreshMessage($delivery->message_id);
            }
        } elseif ($row->source_type === 'event_blast') {
            $log = EventNotificationLog::withoutGlobalScopes()->where('tenant_id', $tenant->id)->find($row->source_id);
            if ($log !== null) {
                $log->update(['status' => $accepted ? EventNotificationLog::STATUS_SENT : EventNotificationLog::STATUS_FAILED, 'sent_at' => $accepted ? ($log->sent_at ?? now()) : null,
                    'metadata' => array_merge($log->metadata ?? [], ['outcome' => $accepted ? 'accepted' : 'refused', 'operator_confirmed' => true])]);
            }
        }
    }

    private function refreshMessage(string $id): void
    {
        $message = CommunityMessage::withoutGlobalScopes()->find($id);
        if ($message !== null) {
            app(CommunityMessages::class)->finishProcessing($message);
        }
    }

    /** @return array<string, mixed> */
    private function state(Model $row): array
    {
        return ['status' => $row->getAttribute('status'), 'reason' => $row->getAttribute('reason'), 'credit_receipt_id' => $row->getAttribute('credit_receipt_id')];
    }
}
