<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\LibraryListRequest;
use App\Models\CommunityLibraryItem;
use App\Models\Tenant;
use App\Services\Community\CommunityContentAccess;
use App\Services\Community\CommunityContentStorage;
use App\Services\Community\CommunityLibrary;
use App\Services\Community\CommunityLibraryRollout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CommunityLibraryController extends Controller
{
    public function index(LibraryListRequest $request, string $tenantSlug, CommunityContentAccess $access, CommunityLibrary $library): JsonResponse
    {
        $tenant = $this->tenant($tenantSlug);
        abort_unless(app(CommunityLibraryRollout::class)->available($tenant), 404);
        $readers = $access->readers($request->session(), $tenant, ['kind' => 'all_members']);
        abort_if($readers->isEmpty(), 404);
        $items = $access->visibleItems($request->session(), $tenant)->where('kind', 'document')
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'ilike', '%'.$request->validated('search').'%'))
            ->with(['currentVersion', 'tenant'])->orderBy('title')->orderBy('id')->paginate(25);

        return response()->json(['organisation' => ['name' => $tenant->name, 'slug' => $tenant->slug], 'entries' => $items->through(fn ($item) => $library->payload($item)), 'contexts' => $readers->map(fn ($member) => ['id' => $member->id, 'name' => $member->name, 'role' => app(\App\Services\Community\MemberAccess::class)->role($request->session(), $member)])->values()])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $tenantSlug, string $item, CommunityContentAccess $access, CommunityLibrary $library): JsonResponse
    {
        $model = $access->readable($request->session(), $this->item($tenantSlug, $item));
        abort_if($model === null, 404);

        return response()->json(['item' => $library->payload($model->load(['currentVersion', 'tenant'])), 'contexts' => $access->readers($request->session(), $model->tenant, $model->audience)->map(fn ($member) => ['id' => $member->id, 'name' => $member->name, 'role' => app(\App\Services\Community\MemberAccess::class)->role($request->session(), $member)])->values()])->header('Cache-Control', 'private, no-store');
    }

    public function file(Request $request, string $tenantSlug, string $item, CommunityContentAccess $access, CommunityContentStorage $storage): StreamedResponse
    {
        $model = $access->readable($request->session(), $this->item($tenantSlug, $item));
        abort_if($model === null || $model->currentVersion === null, 404);

        return $storage->stream($model->currentVersion);
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->where('slug', $slug)->firstOrFail();
    }

    private function item(string $slug, string $id): CommunityLibraryItem
    {
        return CommunityLibraryItem::withoutGlobalScopes()->where('tenant_id', $this->tenant($slug)->id)->findOrFail($id);
    }
}
