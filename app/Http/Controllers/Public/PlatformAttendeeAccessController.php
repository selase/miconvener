<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\ContactMask;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformAttendeeAccessController extends Controller
{
    /**
     * Central platform attendee portal page.
     * Reached on the base domain (e.g. miconvener.com/my).
     */
    public function page(Request $request): Response
    {
        $organiserSlug = $request->query('organiser');
        $organiser = null;

        if ($organiserSlug && is_string($organiserSlug)) {
            $tenant = Tenant::where('slug', $organiserSlug)->first();
            if ($tenant) {
                $organiser = [
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                ];
            }
        }

        $email = null;
        $marker = $request->session()->get('attendee_verified_platform');
        if (is_array($marker) && isset($marker['email'], $marker['expires_at'])) {
            if ($marker['expires_at'] > now()->getTimestamp()) {
                $email = (string) $marker['email'];
            } else {
                $request->session()->forget('attendee_verified_platform');
            }
        }

        return Inertia::render('Public/Events/AttendeePortal/MyPortal', [
            'organiser' => $organiser,
            'verifiedEmail' => $email !== null ? ContactMask::email($email) : null,
        ]);
    }
}
