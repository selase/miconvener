<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLeadRecipientRequest;
use App\Models\PlatformNotificationRecipient;
use Illuminate\Http\RedirectResponse;

/**
 * Who is emailed when someone fills in the Enterprise form.
 */
final class LeadRecipientController extends Controller
{
    public function store(StoreLeadRecipientRequest $request): RedirectResponse
    {
        PlatformNotificationRecipient::query()->create([
            'topic' => PlatformNotificationRecipient::ENTERPRISE_LEADS,
            'email' => $request->validated('email'),
        ]);

        return back()->with('success', 'They will now receive Enterprise enquiries.');
    }

    public function destroy(PlatformNotificationRecipient $recipient): RedirectResponse
    {
        $this->authorize('update setting');

        $recipient->delete();

        return back()->with('success', 'They will no longer receive Enterprise enquiries.');
    }
}
