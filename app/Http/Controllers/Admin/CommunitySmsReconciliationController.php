<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MessageOutcomeFilterRequest;
use App\Http\Requests\Admin\ResolveMessageOutcomeRequest;
use App\Models\CommunityMessageDelivery;
use App\Models\CommunitySmsReservation;
use App\Models\DuesReminder;
use App\Models\EventBlastEmailClaim;
use App\Models\MessageOutcomeResolution;
use App\Models\Tenant;
use App\Services\Community\CommunitySmsReconciler;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

final class CommunitySmsReconciliationController extends Controller
{
    public function index(MessageOutcomeFilterRequest $request): View
    {
        $filters = $request->validated();
        $kind = $filters['source_kind'] ?? 'sms_reservation';
        $tenantId = $filters['tenant_id'] ?? null;
        $cutoff = now()->subMinutes(2);
        $query = match ($kind) {
            'sms_reservation' => CommunitySmsReservation::query()->where('status', 'reserved')->where(fn ($query) => $query->where('reason', 'outcome_unknown')->orWhere('reserved_at', '<', $cutoff)),
            'community_email' => CommunityMessageDelivery::query()->where('channel', 'email')->where(fn ($query) => $query->where('status', 'unknown')->orWhere(fn ($query) => $query->where('status', 'claimed')->where('claimed_at', '<', $cutoff))),
            'event_email' => EventBlastEmailClaim::query()->where(fn ($query) => $query->where('status', 'unknown')->orWhere(fn ($query) => $query->whereIn('status', ['reserved', 'submitting'])->where('claimed_at', '<', $cutoff))),
            'legacy_reminder' => DuesReminder::withoutGlobalScopes()->where('channel', 'sms')->where('status', 'reserved')
                ->where(fn ($query) => $query->where('reason', 'outcome_unknown')->orWhere('created_at', '<', $cutoff))
                ->whereNotIn('id', CommunitySmsReservation::query()->where('source_type', 'dues_reminder')->select('source_id')),
            default => abort(422, 'Choose a supported source kind.'),
        };
        $rows = $query->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))->oldest()->paginate(25)->withQueryString()->through(function ($row) use ($kind) {
            $row->setAttribute('review_context', $this->reviewContext($row, $kind));

            return $row;
        });

        return view('admin.messages.reconciliation', [
            'rows' => $rows, 'kind' => $kind, 'tenantId' => $tenantId,
            'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name']),
            'recent' => MessageOutcomeResolution::query()->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))->latest()->limit(20)->get(),
        ]);
    }

    public function resolve(ResolveMessageOutcomeRequest $request, CommunitySmsReconciler $service): RedirectResponse
    {
        $input = $request->validated();
        $tenant = Tenant::query()->findOrFail($input['tenant_id']);
        $service->resolveSource($request->user(), $tenant, $input['source_kind'], $input['source_id'], $input['outcome'], $input['evidence'], $input['reason']);

        return redirect()->route('admin.messages.reconciliation.index', ['source_kind' => $input['source_kind'], 'tenant_id' => $tenant->id])->with('success', 'Outcome recorded. No message was resent.');
    }

    /** @return array{source: string, destination: string} */
    private function reviewContext(CommunitySmsReservation|CommunityMessageDelivery|EventBlastEmailClaim|DuesReminder $row, string $kind): array
    {
        $address = null;
        $source = $kind.' / '.$row->id;
        try {
            if ($row instanceof CommunitySmsReservation) {
                $source = $row->source_type.' / '.$row->source_id;
                if ($row->source_type === 'community_message') {
                    $address = CommunityMessageDelivery::query()->where('tenant_id', $row->tenant_id)->find($row->source_id)?->address;
                } elseif ($row->source_type === 'event_blast') {
                    $address = \App\Models\EventNotificationLog::withoutGlobalScopes()->where('tenant_id', $row->tenant_id)->find($row->source_id)?->recipient_phone;
                } elseif ($row->source_type === 'dues_reminder') {
                    $reminder = DuesReminder::withoutGlobalScopes()->where('tenant_id', $row->tenant_id)->find($row->source_id);
                    $address = $reminder === null ? null : \App\Models\Member::withoutGlobalScopes()->where('tenant_id', $row->tenant_id)->find($reminder->member_id)?->phone;
                }
            } elseif ($row instanceof CommunityMessageDelivery) {
                $address = $row->address;
                $source = 'Community message / '.$row->message_id;
            } elseif ($row instanceof EventBlastEmailClaim) {
                $source = 'Event announcement / '.$row->blast_id;
                $recipient = \App\Models\EventBlastRecipient::withoutGlobalScopes()->where('tenant_id', $row->tenant_id)->find($row->recipient_id);
                $address = $recipient === null ? null : \App\Models\EventRegistration::withoutGlobalScopes()->where('tenant_id', $row->tenant_id)->find($recipient->registration_id)?->email;
            } else {
                $address = \App\Models\Member::withoutGlobalScopes()->where('tenant_id', $row->tenant_id)->find($row->member_id)?->phone;
            }
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            $address = null;
        }
        if (blank($address)) {
            return ['source' => $source, 'destination' => 'Not recorded or unavailable'];
        }
        $masked = str_contains($address, '@') ? mb_substr($address, 0, 1).'***@'.explode('@', $address, 2)[1] : mb_substr($address, 0, 4).'…'.mb_substr($address, -4);

        return ['source' => $source, 'destination' => $masked];
    }
}
