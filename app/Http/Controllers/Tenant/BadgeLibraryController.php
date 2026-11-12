<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ApplyBadgeLibraryRequest;
use App\Http\Requests\Tenant\SaveBadgeLibraryRequest;
use App\Models\BadgeDesignRevision;
use App\Models\Event;
use App\Models\EventBadgeTemplate;
use App\Services\Badges\BadgeLibraryService;
use App\Services\Badges\BadgeTemplateService;
use App\Services\Design\ArtifactArtworkService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BadgeLibraryController extends Controller
{
    public function __construct(private readonly BadgeLibraryService $library) {}

    public function index(string $subdomain, string $event): JsonResponse
    {
        $event = $this->event($event, 'read badge-template');
        $designs = $this->library->visible($event)->with(['versions' => fn ($query) => $query->orderByDesc('version')->limit(1)])->orderBy('name')->get();

        $licences = \App\Models\BadgeTemplateLicence::query()->where('buyer_tenant_id', $event->tenant_id)->where('event_id', $event->id)->with('currentPurchase')->get()->keyBy('family_id');
        $canPurchase = true;
        $checkoutReady = true;
        try {
            app(\App\Services\Badges\BadgeTemplatePurchases::class)->authorize(request()->user(), $event);
        } catch (\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpException) {
            $canPurchase = false;
        }
        try {
            app(\App\Services\Badges\BadgeTemplateReadiness::class)->agreement($event->tenant);
        } catch (\Illuminate\Validation\ValidationException) {
            $checkoutReady = false;
        }

        return response()->json([
            'designs' => $designs->map(fn (\App\Models\BadgeLibraryDesign $design): array => [
                ...$this->licensing($event, $design->versions->first(), $licences),
                'licence_state' => $licences->get($design->id)?->state,
                'id' => $design->id, 'name' => $design->name, 'scope' => $design->scope, 'price_pesewas' => $design->price_pesewas,
                'versions' => $design->versions->map(fn (\App\Models\BadgeLibraryVersion $version): array => ['id' => $version->id, 'version' => $version->version, 'width_mm' => $version->snapshotSettings()['width_mm'], 'height_mm' => $version->snapshotSettings()['height_mm'], 'orientation' => $version->snapshotSettings()['orientation'], 'compositions' => array_keys($version->snapshotSettings()['compositions'] ?? [])]),
            ]),
            'revisions' => BadgeDesignRevision::query()->where('tenant_id', $event->tenant_id)->where('event_id', $event->id)->latest()->limit(30)->get(['id', 'design_version', 'created_at']),
            'purchases' => $canPurchase ? \App\Models\BadgeTemplatePurchase::query()->where('buyer_tenant_id', $event->tenant_id)->where('event_id', $event->id)->with(['order.receipt.outbox', 'order.attempts', 'licence', 'event.tenant', 'version'])->withExists('uses')->latest()->orderByDesc('id')->limit(20)->get()->map(fn ($purchase): array => app(\App\Services\Badges\BadgeTemplatePurchasePayload::class)->forPurchase($purchase)) : [],
            'can_purchase' => $canPurchase,
            'checkout_ready' => $checkoutReady,
            'can_share' => request()->user()->can('manage badge-library'),
            'can_publish' => request()->user()->isGlobalSuperAdmin(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(SaveBadgeLibraryRequest $request, string $subdomain, string $event): JsonResponse
    {
        $event = $this->event($event, 'update badge-template');
        $scope = $request->validated('scope');
        if ($scope === 'organisation') {
            $this->authorize('manage badge-library');
        }
        if ($scope === 'platform') {
            abort_unless($request->user()->isGlobalSuperAdmin(), 403);
        }
        $version = $this->library->save($event, $request->validated());

        return response()->json(['version' => ['id' => $version->id, 'version' => $version->version]], 201);
    }

    public function apply(ApplyBadgeLibraryRequest $request, string $subdomain, string $event): JsonResponse
    {
        $event = $this->event($event, 'update badge-template');

        return response()->json(['template' => $this->library->apply($event, $request->validated())]);
    }

    public function restore(ApplyBadgeLibraryRequest $request, string $subdomain, string $event): JsonResponse
    {
        abort_unless($request->validated('revision_id') !== null, 422);

        return $this->apply($request, $subdomain, $event);
    }

    public function preview(\App\Http\Requests\Tenant\PreviewBadgeLibraryRequest $request, string $subdomain, string $event, string $version): JsonResponse
    {
        $event = $this->event($event, 'read badge-template');
        $version = $this->library->visibleVersion($event, $version);
        if (app(\App\Services\Badges\BadgeTemplateAccess::class)->families('version', $version->id) !== []) {
            return response()->json(['preview_image' => $this->library->previewImage($version->snapshotSettings(), true, [...$request->validated(), 'tenant_id' => $event->tenant_id])])->header('Cache-Control', 'private, no-store');
        }
        $snapshot = $version->snapshotSettings();
        $composition = $request->query('composition');
        if (is_string($composition) && isset($snapshot['compositions'][$composition])) {
            $snapshot = $snapshot['compositions'][$composition];
        }
        unset($snapshot['compositions']);
        $template = new EventBadgeTemplate(array_intersect_key($snapshot, array_flip(BadgeLibraryService::FIELDS)));
        $background = is_string($template->background_disk) && is_string($template->background_path) ? app(ArtifactArtworkService::class)->dataUri($template->background_disk, $template->background_path) : null;
        $logo = app(BadgeTemplateService::class)->badgeLogo($event, $template);
        if (is_array($snapshot['back'] ?? null)) {
            $back = $snapshot['back'];
            $snapshot['back_background'] = is_string($back['background_path'] ?? null) ? app(ArtifactArtworkService::class)->dataUri($back['background_disk'], $back['background_path']) : null;
            unset($snapshot['back']['background_disk'], $snapshot['back']['background_path']);
        }
        unset($snapshot['background_disk'], $snapshot['background_path'], $snapshot['logo_disk'], $snapshot['logo_path']);

        return response()->json(['template' => $snapshot, 'background' => $background, 'logo' => $logo])->header('Cache-Control', 'private, no-store');
    }

    public function thumbnail(string $subdomain, string $event, string $version): \Symfony\Component\HttpFoundation\Response
    {
        $event = $this->event($event, 'read badge-template');
        $version = $this->library->visibleVersion($event, $version);
        $uri = $this->library->previewImage($version->snapshotSettings(), app(\App\Services\Badges\BadgeTemplateAccess::class)->families('version', $version->id) !== []);

        return response(base64_decode(explode(',', $uri, 2)[1], true), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
    }

    public function withdraw(Request $request, string $subdomain, string $event, string $design): JsonResponse
    {
        $event = $this->event($event, 'update badge-template');
        $design = $this->library->visible($event)->findOrFail($design);
        if ($design->scope === 'platform') {
            abort_unless($request->user()->isGlobalSuperAdmin(), 403);
        } elseif ($design->scope === 'organisation') {
            $this->authorize('manage badge-library');
        }
        $design->update(['withdrawn_at' => now()]);

        return response()->json(['message' => 'Design withdrawn. Existing event copies are preserved.']);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, \App\Models\BadgeTemplateLicence>  $licences
     * @return array<string, mixed>
     */
    private function licensing(Event $event, ?\App\Models\BadgeLibraryVersion $version, \Illuminate\Support\Collection $licences): array
    {
        $families = $version === null ? [] : app(\App\Services\Badges\BadgeTemplateAccess::class)->families('version', $version->id);
        $requirements = [];
        foreach ($families as $family) {
            $licence = $licences->get($family);
            if ($licence?->state === 'active' && $licence->currentPurchase?->state === 'paid') {
                continue;
            }
            $root = \App\Models\BadgeLibraryVersion::query()->with('design')->where('design_id', $family)->latest('version')->firstOrFail();
            $requirements[] = ['family_id' => $family, 'version_id' => $root->id, 'name' => $root->design->name];
        }

        return ['requires_licence' => $families !== [], 'purchased' => $families !== [] && $requirements === [], 'purchase_requirements' => $requirements];
    }

    private function event(string $id, string $permission): Event
    {
        $this->authorize($permission);
        $tenant = app(TenantContext::class)->getTenant();
        abort_if($tenant === null, 403);

        return Event::query()->where('tenant_id', $tenant->id)->findOrFail($id);
    }
}
