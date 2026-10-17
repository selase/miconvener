<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\Giving\SubscribeGivingReminderRequest;
use App\Http\Requests\Public\Giving\UnsubscribeGivingReminderRequest;
use App\Models\GivingFund;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Giving\GivingReminders;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class GivingReminderController extends Controller
{
    public function subscribe(SubscribeGivingReminderRequest $request, string $subdomain, string $slug, GivingReminders $reminders): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_unless(PlatformAttendeeVerification::getVerifiedEmail($request->session()) !== null, 401);
        $fund = GivingFund::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('slug', $slug)->firstOrFail();
        $input = $request->validated();
        $row = $reminders->subscribe($fund, $request->session(), $input['cadence'], isset($input['anchor_day']) ? (int) $input['anchor_day'] : null, true);

        return response()->json(['message' => 'Your optional email reminders are on. Nothing will be charged automatically.', 'next_due_at' => $row->next_due_at->toIso8601String()])->header('Cache-Control', 'private, no-store');
    }

    public function unsubscribe(Request $request, string $subdomain, string $reminder, GivingReminders $reminders): Response
    {
        $tenant = app(TenantContext::class)->getTenant();
        $row = $reminders->forToken($tenant->id, $reminder, (string) $request->query('token'));
        $response = \Inertia\Inertia::render('Public/Giving/Unsubscribe', ['reminderId' => $row->id, 'token' => $request->query('token'), 'stopped' => $row->disabled_at !== null])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    public function stop(UnsubscribeGivingReminderRequest $request, string $subdomain, string $reminder, GivingReminders $reminders): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        $row = $reminders->forToken($tenant->id, $reminder, $request->validated('token'));
        $row->update(['disabled_at' => now(), 'delivery_state' => 'stopped']);

        return response()->json(['message' => 'Giving reminders stopped.'])->header('Cache-Control','private, no-store');
    }
}
