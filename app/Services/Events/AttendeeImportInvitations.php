<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Jobs\Events\DeliverAttendeeImportInvitation;
use App\Mail\Events\EventRegistrationVerifyEmail;
use App\Models\Event;
use App\Models\EventAttendeeImport;
use App\Models\EventAttendeeImportInvitation;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class AttendeeImportInvitations
{
    /**
     * @param  list<string>  $ids
     * @param  array<string,string>  $revisions
     */
    public function select(EventAttendeeImport $import, string $operationKey, array $ids, array $revisions): void
    {
        sort($ids);
        $digest = hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR));
        $import->getConnection()->transaction(function () use ($import, $operationKey, $ids, $digest, $revisions): void {
            EventAttendeeImport::query()->lockForUpdate()->findOrFail($import->id);
            $event = Event::query()->where('tenant_id', $import->tenant_id)->findOrFail($import->event_id);
            abort_unless($event->isPublished() && ($event->ends_at ?? $event->starts_at)->isFuture(), 409, 'Publish the event before sending access invitations.');
            $db = $import->getConnection();
            $run = $db->table('event_attendee_import_invite_runs')->where('import_id', $import->id)->where('operation_key', $operationKey)->first();
            if ($run !== null) {
                abort_unless(hash_equals($run->selection_digest, $digest), 409, 'This invitation operation was already submitted with different recipients.');

                return;
            }
            $registrations = EventRegistration::query()->where('tenant_id', $import->tenant_id)->where('event_id', $import->event_id)->where('attendee_import_id', $import->id)->whereIn('id', $ids)->confirmed()->get();
            abort_unless($registrations->count() === count($ids), 422, 'Choose only confirmed attendees created by this import.');
            $db->table('event_attendee_import_invite_runs')->insert(['import_id' => $import->id, 'operation_key' => $operationKey, 'selection_digest' => $digest, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($registrations as $registration) {
                abort_unless(isset($revisions[$registration->id]) && hash_equals($registration->memberBenefitRecipientToken(), $revisions[$registration->id]), 409, 'Attendee details changed after review. Refresh this result and review the current recipients before sending.');
                EventAttendeeImportInvitation::query()->firstOrCreate(['import_id' => $import->id, 'registration_id' => $registration->id], ['tenant_id' => $import->tenant_id, 'recipient' => $registration->email]);
            }
        });
        $this->dispatchPending($import->id);
    }

    public function dispatchPending(string $importId): void
    {
        EventAttendeeImportInvitation::withoutGlobalScopes()->where('import_id', $importId)->whereIn('status', ['pending', 'failed'])->limit(100)->get()->each(function ($invitation): void {
            try {
                DeliverAttendeeImportInvitation::dispatch($invitation->id)->afterCommit();
            } catch (Throwable) {
                $invitation->update(['message' => 'Queue unavailable. Retry invitations to enqueue untouched recipients.']);
            }
        });
    }

    public function deliver(string $id): void
    {
        $candidate = EventAttendeeImportInvitation::withoutGlobalScopes()->find($id);
        if ($candidate === null || ! in_array($candidate->status, ['pending', 'failed'], true)) {
            return;
        }
        $context = app(TenantContext::class);
        $oldTenant = $context->getTenant();
        $oldId = $context->activeTenantId();
        $oldTeam = getPermissionsTeamId();
        try {
            $tenant = Tenant::find($candidate->tenant_id);
            $import = EventAttendeeImport::withoutGlobalScopes()->find($candidate->import_id);
            $actor = $import ? User::find($import->user_id) : null;
            $event = $import ? Event::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->find($import->event_id) : null;
            if ($tenant === null || $actor === null || $event === null) {
                $candidate->update(['status' => 'cancelled', 'message' => 'Event or staff account unavailable.']);

                return;
            }
            $context->setTenant($tenant);
            setPermissionsTeamId($tenant->id);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            if ($tenant->status !== \App\Enum\TenantStatusEnum::ACTIVE || $actor->status === User::STATUS_INACTIVE || (! $actor->isGlobalSuperAdmin() && ! $tenant->users()->whereKey($actor->id)->exists()) || ! $actor->can('update event') || ! EventStaffScope::canAccessEvent($actor, $event) || ! $event->isPublished() || ($event->ends_at ?? $event->starts_at)->isPast()) {
                $candidate->update(['status' => 'failed', 'message' => 'Restore staff event permission and publication before retrying.']);

                return;
            }
            $registration = EventRegistration::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('event_id', $event->id)->where('attendee_import_id', $import->id)->find($candidate->registration_id);
            if ($registration === null || ! $registration->isConfirmed() || mb_strtolower(mb_trim($registration->email)) !== mb_strtolower(mb_trim($candidate->recipient))) {
                $candidate->update(['status' => 'cancelled', 'message' => 'Attendee details changed. This frozen invitation will not be delivered.']);

                return;
            }
            $claimed = $candidate->getConnection()->transaction(function () use ($candidate): bool {
                return EventAttendeeImportInvitation::withoutGlobalScopes()->whereKey($candidate->id)->whereIn('status', ['pending', 'failed'])->update(['status' => 'sending', 'claimed_at' => now(), 'message' => null]) === 1;
            });
            if (! $claimed) {
                return;
            }
            try {
                Mail::to($candidate->recipient)->send(new EventRegistrationVerifyEmail($registration));
                $candidate->update(['status' => 'sent', 'sent_at' => now()]);
            } catch (Throwable) {
                $candidate->update(['status' => 'uncertain', 'message' => 'Delivery outcome is unknown. Check the mail provider before any manual resend; an email may already have been delivered.']);
            }
        } finally {
            if ($oldTenant !== null) {
                $context->setTenant($oldTenant);
            } else {
                $context->clear();
            }
            $context->setActiveTenantId($oldId);
            setPermissionsTeamId($oldTeam);
        }
    }

    public function recover(): void
    {
        EventAttendeeImportInvitation::withoutGlobalScopes()->where('status', 'sending')->where('claimed_at', '<', now()->subMinutes(5))->update(['status' => 'uncertain', 'message' => 'Interrupted delivery; verify the provider outcome before manual resend.']);
        EventAttendeeImportInvitation::withoutGlobalScopes()->whereIn('status', ['pending', 'failed'])->select('import_id')->distinct()->limit(100)->pluck('import_id')->each(fn ($id) => $this->dispatchPending($id));
    }
}
