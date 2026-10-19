<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Venue;

use App\Enum\MemberStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Venue\DeclineInternalBookingRequest;
use App\Http\Requests\Tenant\Venue\StoreInternalBookingRequest;
use App\Http\Requests\Tenant\Venue\UpdateBookingLimitsRequest;
use App\Models\Member;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\VenueBooking;
use App\Services\Tenancy\FeatureService;
use App\Services\Tenancy\TenantContext;
use App\Services\Venue\InternalBookings;
use App\Services\Venue\StaffDomains;
use App\Services\Venue\StaffIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The facility team's side of staff room booking: see what is waiting, book on
 * behalf of staff, approve, decline and cancel. Every action needs the manage
 * room bookings permission and a plan with staff room booking.
 */
final class InternalBookingController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext, private readonly InternalBookings $bookings) {}

    public function index(Request $request, StaffDomains $domains): Response
    {
        $tenant = $this->tenant();
        $this->authorizeAccess($request, $tenant);

        $mine = fn () => VenueBooking::query()->with(['listing'])->where('tenant_id', $tenant->id)->where('kind', VenueBooking::KIND_INTERNAL);
        $row = fn (VenueBooking $b): array => [
            'id' => $b->id,
            'room' => $b->listing->title,
            'for' => $b->planner_name,
            'email' => $b->planner_email,
            'department' => $b->department,
            'purpose' => $b->purpose,
            'guest_count' => $b->guest_count,
            'starts_at' => $b->starts_at->toIso8601String(),
            'ends_at' => $b->ends_at->toIso8601String(),
            'status' => $b->status,
            'entered_by_team' => $b->entered_by_user_id !== null,
        ];

        return Inertia::render('Tenant/Venue/InternalBookings/Index', [
            'pending' => $mine()->where('status', VenueBooking::STATUS_PENDING_APPROVAL)->orderBy('starts_at')->get()->map($row)->values(),
            'upcoming' => $mine()->where('status', VenueBooking::STATUS_CONFIRMED)->where('ends_at', '>=', now())->orderBy('starts_at')->limit(50)->get()->map($row)->values(),
            'rooms' => StoreListing::query()->withStaffOnly()->whereHas('shop', fn ($shop) => $shop->where('tenant_id', $tenant->id))
                ->where('status', StoreListing::STATUS_PUBLISHED)->orderBy('title')->get(['id', 'title', 'audience'])
                ->map(fn (StoreListing $room): array => ['id' => $room->id, 'title' => $room->title, 'audience' => $room->audience])->values(),
            'staff' => Member::query()->where('tenant_id', $tenant->id)->where('is_staff', true)->where('status', '!=', MemberStatusEnum::Exited->value)
                ->orderBy('name')->get(['id', 'name', 'email'])->map(fn (Member $m): array => ['id' => $m->id, 'name' => $m->name, 'email' => $m->email])->values(),
            'domains' => collect($tenant->staff_email_domains ?? [])->map(fn (array $entry): array => [
                'domain' => $entry['domain'],
                'verified' => ($entry['verified_at'] ?? null) !== null,
                'record_name' => $domains->record($entry)['name'],
                'record_value' => $domains->record($entry)['value'],
            ])->values(),
            'limits' => [...config('bookings.staff.defaults'), ...($tenant->booking_limits ?? [])],
        ]);
    }

    public function store(StoreInternalBookingRequest $request, StaffIdentity $identity): RedirectResponse
    {
        $tenant = $this->tenant();
        $room = StoreListing::query()->withStaffOnly()->whereHas('shop', fn ($shop) => $shop->where('tenant_id', $tenant->id))->findOrFail($request->validated('store_listing_id'));

        $member = $request->validated('member_id')
            ? Member::query()->where('tenant_id', $tenant->id)->findOrFail($request->validated('member_id'))
            : $identity->tagOrEnrol($tenant, (string) ($request->validated('staff_name') ?? $request->validated('staff_email')), (string) $request->validated('staff_email'));

        $this->bookings->request(
            tenant: $tenant,
            room: $room,
            for: $member,
            from: CarbonImmutable::parse((string) $request->validated('starts_at')),
            to: CarbonImmutable::parse((string) $request->validated('ends_at')),
            purpose: (string) $request->validated('purpose'),
            department: $request->validated('department'),
            guests: $request->validated('guest_count') === null ? null : (int) $request->validated('guest_count'),
            enteredBy: $request->user(),
            confirmNow: (bool) $request->validated('confirm'),
        );

        return back()->with('success', 'Booking saved.');
    }

    public function approve(Request $request, string $subdomain, string $booking): RedirectResponse
    {
        $this->authorizeAccess($request, $this->tenant());
        $this->bookings->approve($this->find($booking), $request->user());

        return back()->with('success', 'Request approved. The staff member has been told.');
    }

    public function decline(DeclineInternalBookingRequest $request, string $subdomain, string $booking): RedirectResponse
    {
        $this->bookings->decline($this->find($booking), $request->user(), (string) $request->validated('reason'));

        return back()->with('success', 'Request declined. The staff member has been told why.');
    }

    public function cancel(Request $request, string $subdomain, string $booking): RedirectResponse
    {
        $this->authorizeAccess($request, $this->tenant());
        $this->bookings->cancel($this->find($booking), $request->user());

        return back()->with('success', 'Booking cancelled.');
    }

    public function updateLimits(UpdateBookingLimitsRequest $request): RedirectResponse
    {
        $this->tenant()->update(['booking_limits' => $request->validated()]);

        return back()->with('success', 'Booking limits saved.');
    }

    public function storeDomain(Request $request, StaffDomains $domains): RedirectResponse
    {
        $tenant = $this->tenant();
        $this->authorizeAccess($request, $tenant);
        $record = $domains->add($tenant, (string) $request->validate(['domain' => ['required', 'string', 'max:253']])['domain']);

        return back()->with('success', "Publish a TXT record named {$record['name']} with the value shown below, then choose Check.");
    }

    public function verifyDomain(Request $request, StaffDomains $domains): RedirectResponse
    {
        $tenant = $this->tenant();
        $this->authorizeAccess($request, $tenant);
        $domain = (string) $request->validate(['domain' => ['required', 'string', 'max:253']])['domain'];

        return $domains->verify($tenant, $domain)
            ? back()->with('success', "{$domain} is verified. People who verify an address there now count as staff.")
            : back()->with('error', 'We could not find that record yet. DNS changes can take a while to spread; try again shortly.');
    }

    public function destroyDomain(Request $request, StaffDomains $domains): RedirectResponse
    {
        $tenant = $this->tenant();
        $this->authorizeAccess($request, $tenant);
        $domains->remove($tenant, (string) $request->validate(['domain' => ['required', 'string', 'max:253']])['domain']);

        return back()->with('success', 'Domain removed. Addresses there no longer count as staff.');
    }

    /** Tag someone as staff, or add them as a member without dues. */
    public function storeStaff(Request $request, StaffIdentity $identity): RedirectResponse
    {
        $tenant = $this->tenant();
        $this->authorizeAccess($request, $tenant);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255']]);

        $identity->tagOrEnrol($tenant, $data['name'], $data['email']);

        return back()->with('success', 'Added to staff.');
    }

    /** Stop treating someone as staff. They stay a member and keep bookings already confirmed. */
    public function destroyStaff(Request $request, string $subdomain, string $member): RedirectResponse
    {
        $tenant = $this->tenant();
        $this->authorizeAccess($request, $tenant);

        Member::query()->where('tenant_id', $tenant->id)->findOrFail($member)->update(['is_staff' => false]);

        return back()->with('success', 'Removed from staff.');
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenantContext->getTenant();

        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    private function authorizeAccess(Request $request, Tenant $tenant): void
    {
        abort_unless($request->user()?->can('manage room bookings') && $tenant->featureEnabled(FeatureService::FEATURE_STAFF_ROOM_BOOKING), 403);
    }

    /** Only this organisation's internal bookings resolve, so another's cannot be touched. */
    private function find(string $id): VenueBooking
    {
        return VenueBooking::query()->with('listing')->where('tenant_id', $this->tenant()->id)->where('kind', VenueBooking::KIND_INTERNAL)->findOrFail($id);
    }
}
