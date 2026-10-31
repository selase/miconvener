<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Mail\EnterpriseEnquiryReceived;
use App\Mail\NewEnterpriseLead;
use App\Models\Lead;
use App\Models\PlatformNotificationRecipient;
use App\Services\Platform\PlatformStaff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

final class LeadController extends Controller
{
    public function store(Request $request)
    {
        // 1. Basic Rate Limiting (Anti-Spam)
        $executed = RateLimiter::attempt(
            'lead-submission:'.$request->ip(),
            $perMinute = 3,
            fn (): true => true
        );

        if (! $executed) {
            return back()->with('error', 'Too many requests. Please try again later.');
        }

        // 2. Validation
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'company' => 'nullable|string|max:255',
            'message' => 'required|string|min:10',
        ]);

        // 3. Persist
        $lead = Lead::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'company' => $validated['company'] ?? null,
            'message' => $validated['message'],
            'ip_address' => $request->ip(),
            'status' => 'new',
        ]);

        // 4. Tell the people chosen to receive enquiries (set in the console),
        // or, if nobody is set, the staff who follow up signups. The sending
        // address takes no mail, so it is never a recipient.
        $recipients = PlatformNotificationRecipient::emailsFor(PlatformNotificationRecipient::ENTERPRISE_LEADS);
        if ($recipients === []) {
            $recipients = app(PlatformStaff::class)->emailsWithPermission('follow up signups');
        }

        if ($recipients === []) {
            Log::warning('Enterprise enquiry saved but nobody is set to receive it', ['lead_id' => $lead->id]);
        } else {
            Mail::to($recipients)->queue(new NewEnterpriseLead($lead));
        }

        Mail::to($lead->email)->queue(new EnterpriseEnquiryReceived($lead, $recipients));

        return back()->with('success', 'Thank you! Our sales team will reach out to you shortly.');
    }
}
