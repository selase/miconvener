<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\InboxListRequest;
use App\Http\Requests\Attendee\MarkInboxAllReadRequest;
use App\Http\Requests\Attendee\MarkInboxReadRequest;
use App\Models\Tenant;
use App\Services\Community\InboxQuery;
use App\Services\Community\InboxReads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MyInboxController extends Controller
{
    public function index(Request $request, InboxQuery $query): JsonResponse
    {
        return response()->json(['organisations' => $query->counts($request->session())])->header('Cache-Control', 'private, no-store');
    }

    public function organisation(InboxListRequest $request, string $tenantSlug, InboxQuery $query): JsonResponse
    {
        return response()->json(['entries' => $query->entries($request->session(), $this->tenant($tenantSlug), $request->validated())])->header('Cache-Control', 'private, no-store');
    }

    public function show(InboxListRequest $request, string $tenantSlug, string $item, InboxQuery $query): JsonResponse
    {
        return response()->json(['entry' => $query->entry($request->session(), $this->tenant($tenantSlug), $item, $request->validated('context') ?? 'self')])->header('Cache-Control', 'private, no-store');
    }

    public function read(MarkInboxReadRequest $request, string $tenantSlug, string $item, InboxReads $reads): JsonResponse
    {
        $reads->mark($request->session(), $this->tenant($tenantSlug), $item, $request->validated('context'));

        return response()->json(['read' => true])->header('Cache-Control', 'private, no-store');
    }

    public function readAll(MarkInboxAllReadRequest $request, string $tenantSlug, InboxReads $reads): JsonResponse
    {
        $reads->markAll($request->session(), $this->tenant($tenantSlug));

        return response()->json(['read' => true])->header('Cache-Control', 'private, no-store');
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->where('slug', $slug)->firstOrFail();
    }
}
