<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\Events\SendEventBlastJob;
use App\Models\Event;
use App\Models\EventBlast;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class EventBlastController extends Controller
{
    public function index(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $blasts = $eventModel->blasts()->withCount(['recipients as opened_count' => fn ($q) => $q->whereNotNull('opened_at')])
            ->with('sentBy:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'blasts' => $blasts->map(fn (EventBlast $blast): array => [
                'id' => $blast->id,
                'subject' => $blast->subject,
                'body' => $blast->body,
                'audience' => $blast->audience,
                'audience_label' => $blast->audience_label,
                'recipients_count' => $blast->recipients_count,
                'opened_count' => $blast->opened_count,
                'status' => $blast->status,
                'scheduled_at' => $blast->scheduled_at?->toIso8601String(),
                'sent_at' => $blast->sent_at?->toIso8601String(),
                'sent_by' => $blast->sentBy ? "{$blast->sentBy->first_name} {$blast->sentBy->last_name}" : null,
                'created_at' => $blast->created_at->toIso8601String(),
            ]),
            'audience_options' => EventBlast::audienceOptions($eventModel),
        ]);
    }

    public function store(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'audience' => ['required', 'string'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);

        $options = collect(EventBlast::audienceOptions($eventModel));
        $option = $options->firstWhere('key', $validated['audience']);
        if (! $option) {
            throw ValidationException::withMessages(['audience' => 'That audience is no longer available.']);
        }

        $recipientsCount = EventBlast::audienceQuery($eventModel, $validated['audience'])->count();

        $blast = $eventModel->blasts()->create([
            'tenant_id' => $tenant->id,
            'sent_by' => $request->user()->id,
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'audience' => $validated['audience'],
            'audience_label' => $option['label'],
            'recipients_count' => $recipientsCount,
            'status' => EventBlast::STATUS_SCHEDULED,
            'scheduled_at' => $validated['scheduled_at'] ?? null,
        ]);

        $dispatch = SendEventBlastJob::dispatch($blast);
        if ($blast->scheduled_at) {
            $dispatch->delay($blast->scheduled_at);
        }

        $message = $blast->scheduled_at
            ? "Blast scheduled for {$recipientsCount} recipient(s)."
            : "Blast queued for {$recipientsCount} recipient(s).";

        return response()->json(['message' => $message, 'recipients_count' => $recipientsCount]);
    }

    public function cancel(string $subdomain, string $event, string $blast): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $blastModel = $eventModel->blasts()->where('id', $blast)->firstOrFail();

        if ($blastModel->status !== EventBlast::STATUS_SCHEDULED || ! $blastModel->scheduled_at?->isFuture()) {
            return response()->json(['message' => 'Only a blast still waiting to send can be cancelled.'], 422);
        }

        $blastModel->update(['status' => EventBlast::STATUS_CANCELLED]);

        return response()->json(['message' => 'Blast cancelled.']);
    }

    private function findEvent(string $tenantId, string $eventId): Event
    {
        return Event::where('tenant_id', $tenantId)->where('id', $eventId)->firstOrFail();
    }

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }
}
