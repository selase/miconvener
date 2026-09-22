<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\EventMaterialDownload;
use App\Models\EventRegistration;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MaterialDownloadController extends Controller
{
    public function download(Request $request, string $subdomain, string $event, string $registration, string $material): StreamedResponse
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

        $disk = Event::uploadDisk();

        /*
         * An attempt is only spent on a file that can actually be served. The
         * limit is small, so one lost to a missing file is one the attendee
         * never gets back.
         */
        if (! Storage::disk($disk)->exists($materialModel->file_path)) {
            abort(404);
        }

        EventMaterialDownload::create([
            'tenant_id' => $tenant->id,
            'material_id' => $materialModel->id,
            'registration_id' => $registrationModel->id,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        /*
         * Streamed through the app, which holds the storage credentials, rather
         * than redirected to the object's URL. The bucket is private, so that
         * URL answered 400 in production; and had it worked, it would have been
         * a permanent link outliving the release date, the registration and the
         * download limit this controller exists to enforce. Inline, so a PDF
         * opens in the attendee's browser instead of being saved.
         */
        return Storage::disk($disk)->response(
            $materialModel->file_path,
            $this->filenameFor($materialModel),
            array_filter([
                'Content-Type' => $materialModel->mime_type,
                'Cache-Control' => 'private, no-store',
            ]),
        );
    }

    /**
     * The name the attendee sees: the material's title with the stored file's
     * extension, rather than the random name it was uploaded under.
     */
    private function filenameFor(EventMaterial $material): string
    {
        $title = mb_trim(str_replace(['/', '\\'], '-', $material->title));
        $extension = pathinfo($material->file_path, PATHINFO_EXTENSION);

        if ($extension === '' || str_ends_with(mb_strtolower($title), '.'.mb_strtolower($extension))) {
            return $title;
        }

        return "{$title}.{$extension}";
    }
}
