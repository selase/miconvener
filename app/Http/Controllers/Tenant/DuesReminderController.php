<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Community\UpdateReminderSettingsRequest;
use App\Jobs\SendDuesReminders;
use App\Models\CommunitySettings;
use App\Models\Tenant;
use App\Services\Dues\DuesReminders;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DuesReminderController extends Controller
{
    public function preview(Request $request, DuesReminders $reminders): JsonResponse
    {
        return response()->json($reminders->previewArrears($this->tenant(), (int) $request->user()->id));
    }

    public function send(Request $request, DuesReminders $reminders): JsonResponse
    {
        $tenant = $this->tenant();
        $reminders->authorizeArrears($tenant, (int) $request->user()->id);
        abort_unless(CommunitySettings::for($tenant)->reminders_enabled, 422, 'Enable dues reminders in Community settings first.');
        SendDuesReminders::dispatch($tenant->id, (int) $request->user()->id)->afterCommit();

        return response()->json(['status' => 'queued', 'message' => 'Arrears reminders queued. Recipients and SMS availability will be checked when sending.'], 202);
    }

    public function updateSettings(UpdateReminderSettingsRequest $request): JsonResponse
    {
        $settings = CommunitySettings::for($this->tenant());
        $settings->fill($request->validated())->save();

        return response()->json(['message' => 'Reminder settings saved.']);
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->getTenant() ?? abort(403, 'Tenant context not resolved.');
    }
}
