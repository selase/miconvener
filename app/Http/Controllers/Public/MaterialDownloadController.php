<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\EventMaterial;
use App\Models\EventMaterialDownload;
use App\Models\EventRegistration;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class MaterialDownloadController extends Controller
{
    public function download(Request $request, string $subdomain, string $event, string $registration, string $material): RedirectResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404);
        }

        $registrationModel = EventRegistration::where('tenant_id', $tenant->id)
            ->where('id', $registration)
            ->firstOrFail();

        if (! $registrationModel->isConfirmed()) {
            abort(403, 'This registration is not confirmed.');
        }

        $materialModel = EventMaterial::where('tenant_id', $tenant->id)
            ->where('id', $material)
            ->where('event_id', $registrationModel->event_id)
            ->firstOrFail();

        if (! $materialModel->isReleased()) {
            abort(403, 'This material has not been released yet.');
        }

        if ($materialModel->remainingAttemptsFor($registrationModel->id) <= 0) {
            abort(429, 'You have used all your download attempts for this file.');
        }

        EventMaterialDownload::create([
            'tenant_id' => $tenant->id,
            'material_id' => $materialModel->id,
            'registration_id' => $registrationModel->id,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        $disk = config('app.env') === 'production' ? 's3' : 'public';

        return redirect(Storage::disk($disk)->url($materialModel->file_path));
    }
}
