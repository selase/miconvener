<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateLeadRequest;
use App\Models\Lead;
use App\Models\PlatformNotificationRecipient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Enterprise enquiries, for the staff who see and follow up signups.
 */
final class LeadController extends Controller
{
    public function index(): View
    {
        $this->authorize('view signups');

        return view('admin.leads.index', [
            'leads' => Lead::query()->orderByDesc('created_at')->paginate(10),
            'recipients' => PlatformNotificationRecipient::query()->where('topic', PlatformNotificationRecipient::ENTERPRISE_LEADS)->orderBy('id')->get(),
        ]);
    }

    public function show(Lead $lead): View
    {
        $this->authorize('view signups');

        return view('admin.leads.show', ['lead' => $lead, 'statuses' => Lead::STATUSES]);
    }

    public function update(UpdateLeadRequest $request, Lead $lead): RedirectResponse
    {
        $status = $request->validated('status');

        $lead->update([
            'status' => $status,
            'notes' => $request->validated('notes'),
            'status_changed_at' => $status === $lead->status ? $lead->status_changed_at : now(),
        ]);

        return back()->with('success', 'Lead updated.');
    }

    public function destroy(Lead $lead): RedirectResponse
    {
        $this->authorize('follow up signups');

        $lead->delete();

        return redirect()->route('admin.leads.index')->with('success', 'Lead deleted successfully.');
    }
}
