<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Mail\NewEnterpriseLead;
use App\Models\Lead;
use Illuminate\Http\Request;
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

        // 4. Dispatch Notification
        Mail::to(config('mail.from.address', 'sales@starterkit.test'))->send(new NewEnterpriseLead($lead));

        return back()->with('success', 'Thank you! Our sales team will reach out to you shortly.');
    }
}
