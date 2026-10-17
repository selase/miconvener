<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\UpdateAttendeePreferenceRequest;
use App\Models\Tenant;
use App\Services\Community\CommunityHistory;
use App\Services\Community\Following;
use App\Services\Community\MessagingPreferences;
use App\Services\Community\PhoneVerification;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AttendeePreferencesController extends Controller
{
    public function index(Request $request, CommunityHistory $history, MessagingPreferences $preferences): JsonResponse
    {
        $rows = $history->communities($request->session());
        $rows['data'] = array_map(function (array $row) use ($request, $preferences): array {
            $tenant = Tenant::query()->findOrFail($row['id']);
            $channels = [];
            foreach (['email', 'sms'] as $channel) {
                $address = $this->address($request, $channel);
                if ($address !== null) {
                    $channels[$channel] = ['marketing_enabled' => $preferences->allowsMarketing($tenant, $channel, $address)];
                }
            }

            return ['tenant_slug' => $tenant->slug, 'name' => $tenant->name, 'channels' => $channels];
        }, $rows['data']);

        return response()->json(['preferences' => $rows])->header('Cache-Control', 'private, no-store');
    }

    public function update(UpdateAttendeePreferenceRequest $request, string $tenantSlug, CommunityHistory $history, Following $following, MessagingPreferences $preferences): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->firstOrFail();
        abort_unless($history->hasOrganisation($tenant, $request->session()), 404);
        $channel = (string) $request->validated('channel');
        $address = $this->address($request, $channel);
        abort_if($address === null, 403, 'Verify this contact first.');
        if ($request->boolean('marketing_enabled')) {
            abort_unless($tenant->status === \App\Enum\TenantStatusEnum::ACTIVE, 404);
            if ($tenant->followingEnabled()) {
                $following->follow($tenant, $request->session(), $channel, $request->boolean('resume_marketing'));
            } else {
                $connection = $tenant->getConnection();
                $connection->transaction(function () use ($connection, $tenant, $channel, $address, $preferences): void {
                    $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['community-follow:'.$tenant->id.':'.$channel.':'.$preferences->hash($channel, $address)]);
                    if ($channel === 'sms' && app(\App\Services\Community\WrongNumberReports::class)->isBlocked($tenant, $channel, $address)) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['channel' => 'This number needs an independent review before SMS announcements can resume.']);
                    }
                    $preferences->enableMarketing($tenant, $channel, $address);
                });
            }
        } else {
            $preferences->disableMarketing($tenant, $channel, $address);
        }

        return response()->json(['message' => 'Announcement preferences updated. Receipts and essential service notices are unchanged.'])->header('Cache-Control', 'private, no-store');
    }

    private function address(Request $request, string $channel): ?string
    {
        return $channel === 'email' ? app(PlatformAttendeeVerification::class)->verifiedEmail($request->session()) : app(PhoneVerification::class)->verifiedPhone($request->session());
    }
}
