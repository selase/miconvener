<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enum\TenantStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\ConfirmPhoneAccessRequest;
use App\Http\Requests\Attendee\PhoneAccessRequest;
use App\Models\Tenant;
use App\Services\Community\PhoneVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CommunityPhoneAccessController extends Controller
{
    public function __construct(private readonly PhoneVerification $verification) {}

    public function send(PhoneAccessRequest $request): JsonResponse
    {
        $this->context($request);
        $result = $this->verification->requestSend($request->string('phone')->toString(), (string) $request->ip(), $request->input('turnstile_token'));
        $status = match ($result['status']) {
            'allowed' => 200,
            'rate_limited' => 429,
            'challenge_required' => 428,
            'invalid', 'challenge_failed' => 422,
            default => 503,
        };
        unset($result['request_id']);

        return response()->json($result + ['message' => $status === 200 ? 'Your sign-in code is on its way.' : 'Cannot send a code now. Try email or wait before trying again.'], $status)->header('Cache-Control', 'private, no-store');
    }

    public function confirm(ConfirmPhoneAccessRequest $request): JsonResponse
    {
        $this->context($request);
        $confirmed = $this->verification->confirm($request->session(), $request->string('phone')->toString(), $request->string('code')->toString(), (string) $request->ip());

        return response()->json(['verified' => $confirmed, 'message' => $confirmed ? 'Phone verified. Existing memberships still require access approval.' : 'That code is invalid or expired.'], $confirmed ? 200 : 422)->header('Cache-Control', 'private, no-store');
    }

    public function forget(Request $request): JsonResponse
    {
        $this->verification->forget($request->session());
        $request->session()->regenerate();

        return response()->json(['forgotten' => true])->header('Cache-Control', 'private, no-store');
    }

    private function context(Request $request): Tenant
    {
        $tenant = Tenant::query()->where('slug', $request->string('organiser')->toString())->where('status', TenantStatusEnum::ACTIVE)->firstOrFail();
        abort_unless($tenant->followingEnabled() || $tenant->communityEnabled(), 404);

        return $tenant;
    }
}
