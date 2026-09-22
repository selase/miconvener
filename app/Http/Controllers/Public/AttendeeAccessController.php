<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\ConfirmAccessCodeRequest;
use App\Http\Requests\Attendee\SendAccessCodeRequest;
use App\Jobs\Events\SendAttendeeAccessCode;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\AttendeeVerification;
use App\Services\Tenancy\TenantContext;
use App\Support\ContactMask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets an attendee prove they hold an address, so the portal can show what is
 * theirs with this organiser.
 */
final class AttendeeAccessController extends Controller
{
    public function __construct(private readonly AttendeeVerification $verification) {}

    public function send(SendAccessCodeRequest $request): JsonResponse
    {
        // No address-dependent work happens here: looking up a registration,
        // hashing a code and queuing mail all cost time that would otherwise
        // tell a caller whether the address (or registration id) means
        // anything here. That work moves to a queued job.
        SendAttendeeAccessCode::dispatch(
            $this->tenant(),
            $request->filled('email') ? (string) $request->input('email') : null,
            $request->filled('registration') ? (string) $request->input('registration') : null,
        );

        // The same answer whatever happened. Anyone can type an address here,
        // and a different reply for "found" would reveal who attends.
        return response()->json([
            'message' => 'If that address has anything with this organiser, a code is on its way to it.',
        ]);
    }

    public function confirm(ConfirmAccessCodeRequest $request): JsonResponse
    {
        $tenant = $this->tenant();

        // A registration id that doesn't resolve still reaches confirm() --
        // with an address no code could ever match, never a short-circuit --
        // so it pays the same Hash::check cost as a wrong code and replies
        // identically. Answering sooner here would itself be a timing tell.
        $email = $this->addressFor($request, $tenant) ?? '';

        if (! $this->verification->confirm($request->session(), $tenant, $email, (string) $request->input('code'))) {
            return response()->json(['message' => "That code didn't match. Check it, or ask for a new one."], 422);
        }

        return response()->json(['email' => ContactMask::email(AttendeeVerification::normalise($email))]);
    }

    public function forget(Request $request): JsonResponse
    {
        $this->verification->forget($request->session(), $this->tenant());

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * Who the visitor has proven to be here. Behind attendee_verified, so the
     * address comes from the session, never from the request.
     */
    public function session(Request $request): JsonResponse
    {
        return response()->json([
            'email' => ContactMask::email((string) $request->attributes->get('attendee_email')),
        ]);
    }

    /**
     * From a portal page the address is the registration's own, never one the
     * visitor typed -- so a forwarded link reaches the pass, not the history.
     */
    private function addressFor(Request $request, Tenant $tenant): ?string
    {
        if ($request->filled('registration')) {
            $email = EventRegistration::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', $request->input('registration'))
                ->value('email');

            return $email !== null ? (string) $email : null;
        }

        return (string) $request->input('email');
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->getTenant() ?? abort(404);
    }
}
