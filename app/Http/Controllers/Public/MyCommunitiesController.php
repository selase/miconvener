<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enum\DuesChargeStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\Community\MyCommunityResource;
use App\Http\Resources\Community\MyMemberResource;
use App\Models\DuesCharge;
use App\Models\Tenant;
use App\Services\Community\CommunityHistory;
use App\Services\Community\PhoneVerification;
use App\Services\Events\PlatformAttendeeVerification;
use App\Support\ContactMask;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MyCommunitiesController extends Controller
{
    public function __construct(private readonly CommunityHistory $history) {}

    public function identity(Request $request): JsonResponse
    {
        $email = app(PlatformAttendeeVerification::class)->verifiedEmail($request->session());
        $phone = app(PhoneVerification::class)->verifiedPhone($request->session());

        return response()->json(['email' => $email === null ? null : ['masked' => ContactMask::email($email), 'expires_at' => $request->session()->get(PlatformAttendeeVerification::SESSION_KEY.'.expires_at')],
            'phone' => $phone === null ? null : ['masked' => '••••'.mb_substr($phone, -4), 'expires_at' => $request->session()->get(PhoneVerification::SESSION_KEY.'.expires_at')],
            'can_access_events' => $email !== null, 'can_access_communities' => $email !== null || $phone !== null]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['communities' => $this->history->communities($request->session())]);
    }

    public function show(Request $request, string $tenantSlug): JsonResponse
    {
        $tenant = $this->tenant($tenantSlug);
        abort_unless($this->history->hasOrganisation($tenant, $request->session()), 404);

        return response()->json(['community' => (new MyCommunityResource($tenant))->resolve($request),
            'members' => $this->history->members($tenant, $request->session())->through(fn ($member) => (new MyMemberResource($member))->resolve($request))]);
    }

    public function options(Request $request, string $tenantSlug): JsonResponse
    {
        $tenant = $this->tenant($tenantSlug);
        $context = app(\App\Services\Community\CommunityPublicContext::class)->for($tenant, $request->session());

        return response()->json([...$context, 'requests' => $this->history->requests($tenant, $request->session()),
            'followUrl' => route('attendee.my.follow.store', ['tenantSlug' => $tenantSlug]),
            'joinUrl' => route('attendee.my.joins.store', ['tenantSlug' => $tenantSlug])]);
    }

    public function member(Request $request, string $tenantSlug, string $member): JsonResponse
    {
        return response()->json(['member' => (new MyMemberResource($this->history->member($this->tenant($tenantSlug), $request->session(), $member)))->resolve($request)]);
    }

    public function charges(Request $request, string $tenantSlug, string $member): JsonResponse
    {
        $model = $this->history->member($this->tenant($tenantSlug), $request->session(), $member);

        return response()->json(['charges' => $model->charges()->orderByDesc('period_start')->orderBy('id')->paginate(20)->through(fn (DuesCharge $charge): array => [
            'id' => $charge->id, 'period_start' => $charge->period_start->toDateString(), 'period_end' => $charge->period_end->toDateString(), 'due_on' => $charge->due_on->toDateString(),
            'amount' => $charge->amount, 'currency' => $charge->currency, 'status' => $charge->status->value, 'paid_at' => $charge->paid_at?->toIso8601String(),
            'receipt_url' => $charge->status === DuesChargeStatusEnum::Paid ? route('attendee.my.communities.receipt', ['tenantSlug' => $tenantSlug, 'member' => $member, 'charge' => $charge->id]) : null])]);
    }

    public function receipt(Request $request, string $tenantSlug, string $member, string $charge): Response
    {
        $model = $this->history->member($this->tenant($tenantSlug), $request->session(), $member);
        $paid = $model->charges()->where('tenant_id', $model->tenant_id)->where('status', DuesChargeStatusEnum::Paid)->findOrFail($charge);

        return Pdf::loadView('pdf.community-dues-receipt', ['member' => $model, 'charge' => $paid])->setPaper('a4')->download('dues-receipt-'.$paid->id.'.pdf')->header('Cache-Control', 'private, no-store');
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->where('slug', $slug)->firstOrFail();
    }
}
