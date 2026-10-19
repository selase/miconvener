<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\ServiceNotesListRequest;
use App\Models\Tenant;
use App\Services\Community\CommunityContentAccess;
use App\Services\Community\CommunityLibrary;
use App\Services\Community\CommunityLibraryRollout;
use App\Services\Community\CommunityServices;
use App\Services\Community\MemberAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CommunityServicesController extends Controller
{
    public function index(ServiceNotesListRequest $request, string $tenantSlug, CommunityServices $services, CommunityContentAccess $access): JsonResponse
    {
        $tenant = $this->tenant($tenantSlug);
        abort_unless(app(CommunityLibraryRollout::class)->available($tenant) && $access->readers($request->session(), $tenant, ['kind' => 'all_members'])->isNotEmpty(), 404);
        $entries = $services->entries($tenant, $request->session(), $request->validated());

        return response()->json([...$services->series($tenant, $request->session(), $request->validated()), 'organisation' => ['name' => $tenant->name, 'timezone' => \App\Models\CommunitySettings::for($tenant)->timezone], 'services' => $entries->through(fn ($entry) => $services->payload($tenant, $entry))])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $tenantSlug, string $session, CommunityServices $services, CommunityContentAccess $access, CommunityLibrary $library): JsonResponse
    {
        $tenant = $this->tenant($tenantSlug);
        abort_unless(app(CommunityLibraryRollout::class)->available($tenant), 404);
        $occurrence = $services->occurrences($tenant)->findOrFail($session);
        $notes = $access->visibleItems($request->session(), $tenant)->where('kind', 'service_note')->where('session_id', $session)->with(['currentVersion', 'tenant'])->orderBy('title')->get();
        abort_if($notes->isEmpty(), 404);
        $occurrence->setAttribute('community_notes_count', $notes->count());
        $contexts = $notes->flatMap(fn ($note) => $access->readers($request->session(), $tenant, $note->audience))->unique('id')->map(fn ($member) => ['id' => $member->id, 'name' => $member->name, 'role' => app(MemberAccess::class)->role($request->session(), $member)])->values();

        return response()->json(['service' => $services->payload($tenant, $occurrence), 'timezone' => \App\Models\CommunitySettings::for($tenant)->timezone, 'notes' => $notes->map(fn ($note) => $library->payload($note))->values(), 'contexts' => $contexts])->header('Cache-Control', 'private, no-store');
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->where('slug', $slug)->firstOrFail();
    }
}
