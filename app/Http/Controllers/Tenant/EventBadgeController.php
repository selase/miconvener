<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateEventBadgeTemplateRequest;
use App\Models\Event;
use App\Models\EventBadgePrint;
use App\Models\EventRegistration;
use App\Services\Badges\BadgeTemplateService;
use App\Services\Design\ArtifactArtworkService;
use App\Services\Design\ArtifactLayoutValidator;
use App\Services\Events\QrCodeGenerator;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

final class EventBadgeController extends Controller
{
    public function __construct(
        private readonly BadgeTemplateService $templates,
        private readonly ArtifactArtworkService $artwork,
        private readonly ArtifactLayoutValidator $layouts,
    ) {}

    public function index(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read badge-template');
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();

        $seatLabels = $eventModel->seatAssignments()->get(['registration_id', 'seat_label'])
            ->keyBy('registration_id');

        $printCounts = $eventModel->badgePrints()->selectRaw('registration_id, count(*) as total')
            ->groupBy('registration_id')
            ->pluck('total', 'registration_id');

        $registrations = $eventModel->registrations()
            ->confirmed()
            ->with(['ticketType:id,name,badge_tier', 'badgePrints' => fn ($q) => $q->limit(1)])
            ->orderBy('full_name')
            ->get();

        $recentPrints = $eventModel->badgePrints()
            ->with(['registration:id,full_name', 'printedBy:id,first_name,last_name'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'template' => $this->templates->forEvent($eventModel),
            'badges' => $registrations->map(fn (EventRegistration $r): array => [
                'id' => $r->id,
                'full_name' => $r->full_name,
                'ticket_type_name' => $r->ticketType?->name,
                'badge_tier' => $r->ticketType?->badge_tier ?? 'general',
                'ticket_code' => $r->ticket_code,
                'seat_label' => $seatLabels->get($r->id)?->seat_label,
                'qr_image' => $r->qr_token ? QrCodeGenerator::svgDataUri($r->qr_token, 160) : null,
                'print_count' => (int) ($printCounts->get($r->id) ?? 0),
            ])->values(),
            'recent_prints' => $recentPrints->map(fn (EventBadgePrint $p): array => [
                'registrant_name' => $p->registration?->full_name,
                'printed_by' => $p->printedBy ? mb_trim($p->printedBy->first_name.' '.$p->printedBy->last_name) : 'Unknown',
                'printed_at' => $p->created_at->toIso8601String(),
            ])->values(),
        ]);
    }

    public function updateTemplate(UpdateEventBadgeTemplateRequest $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update badge-template');
        $tenant = $this->getTenant();
        $eventModel = Event::query()->where('tenant_id', $tenant->id)->whereKey($event)->firstOrFail();
        $template = $this->templates->forEvent($eventModel);
        $validated = $request->validated();
        $oldDisk = $template->background_disk;
        $oldPath = $template->background_path;
        $newArtwork = null;

        if ($request->file('background') !== null) {
            $newArtwork = $this->artwork->store($request->file('background'), (string) $tenant->id, (string) $eventModel->id, 'badge-background');
            $validated['background_disk'] = $newArtwork->disk;
            $validated['background_path'] = $newArtwork->path;
        } elseif ($request->boolean('remove_background')) {
            $validated['background_disk'] = null;
            $validated['background_path'] = null;
        }

        unset($validated['background'], $validated['remove_background']);
        $validated['layout'] = $this->layouts->validate($validated['layout'], 'badge');
        $nextDesignVersion = max(1, (int) $template->design_version) + 1;

        try {
            $template->fill($validated);
            $template->design_version = $nextDesignVersion;
            $template->save();
        } catch (Throwable $exception) {
            if ($newArtwork !== null) {
                $this->artwork->delete($newArtwork->disk, $newArtwork->path);
            }
            throw $exception;
        }

        if (is_string($oldDisk) && is_string($oldPath) && $oldPath !== $template->background_path) {
            $this->artwork->delete($oldDisk, $oldPath);
        }

        return response()->json(['message' => 'Badge design saved.', 'template' => $template->fresh()]);
    }

    public function logPrint(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();

        $validated = $request->validate([
            'registration_ids' => ['required', 'array', 'min:1'],
            'registration_ids.*' => [Rule::exists('event_registrations', 'id')->where('event_id', $eventModel->id)],
        ]);

        $rows = array_map(fn (string $registrationId): array => [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'event_id' => $eventModel->id,
            'registration_id' => $registrationId,
            'printed_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $validated['registration_ids']);

        EventBadgePrint::insert($rows);

        return response()->json(['message' => 'Print logged.']);
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
