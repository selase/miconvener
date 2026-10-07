<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreCertificateTemplateRequest;
use App\Http\Requests\Tenant\UpdateCertificateTemplateRequest;
use App\Models\Event;
use App\Models\EventAbstract;
use App\Models\EventCertificate;
use App\Models\EventCertificateTemplate;
use App\Models\EventRegistration;
use App\Services\Certificates\CertificateDesignVersionService;
use App\Services\Certificates\CertificatePdfService;
use App\Services\Design\ArtifactArtworkService;
use App\Services\Design\ArtifactLayoutValidator;
use finfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EventCertificateController extends Controller
{
    public function __construct(
        private readonly CertificatePdfService $pdfs,
        private readonly CertificateDesignVersionService $designVersions,
        private readonly ArtifactArtworkService $artwork,
        private readonly ArtifactLayoutValidator $layouts,
    ) {}

    public function index(Request $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('read certificate');

        $this->ensureDefaultTemplates($event);

        $templates = $event->certificateTemplates()->get();

        $query = $event->certificates()->with('template');

        if ($request->filled('role')) {
            $query->where('role', $request->query('role'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('recipient_name', 'ilike', "%{$search}%")
                    ->orWhere('recipient_email', 'ilike', "%{$search}%")
                    ->orWhere('verification_code', 'ilike', "%{$search}%");
            });
        }

        $certificates = $query->paginate(25);

        $stats = [
            'total_issued' => $event->certificates()->count(),
            'delegates' => $event->certificates()->where('role', EventCertificateTemplate::ROLE_DELEGATE)->count(),
            'speakers' => $event->certificates()->where('role', EventCertificateTemplate::ROLE_SPEAKER)->count(),
            'presenters' => $event->certificates()->where('role', EventCertificateTemplate::ROLE_PRESENTER)->count(),
            'volunteers' => $event->certificates()->where('role', EventCertificateTemplate::ROLE_VOLUNTEER)->count(),
            'total_downloads' => (int) $event->certificates()->sum('download_count'),
        ];

        $eligible = [
            'checked_in_delegates' => $event->registrations()->where('status', EventRegistration::STATUS_CHECKED_IN)->count(),
            'all_delegates' => $event->registrations()->whereIn('status', [EventRegistration::STATUS_CONFIRMED, EventRegistration::STATUS_CHECKED_IN])->count(),
            'speakers' => $event->speakers()->count(),
            'presenters' => $event->abstracts()->whereIn('status', [EventAbstract::STATUS_ACCEPTED_ORAL, EventAbstract::STATUS_ACCEPTED_POSTER])->count(),
        ];

        return response()->json([
            'templates' => $templates,
            'layout_defaults' => $this->layouts->certificateDefaults(),
            'certificates' => $certificates,
            'stats' => $stats,
            'eligible' => $eligible,
        ]);
    }

    public function storeTemplate(StoreCertificateTemplateRequest $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('create certificate');

        $validated = $request->validated();
        $template = $event->certificateTemplates()->firstOrNew(
            [
                'event_id' => $event->id,
                'role' => $validated['role'],
            ]
        );
        if (! $template instanceof EventCertificateTemplate) {
            abort(500, 'Certificate template could not be resolved.');
        }
        $this->persistTemplate($request, $event, $template, $validated);

        return response()->json([
            'message' => 'Certificate template saved successfully.',
            'template' => $template,
        ]);
    }

    public function updateTemplate(UpdateCertificateTemplateRequest $request, string $subdomain, Event $event, EventCertificateTemplate $template): JsonResponse
    {
        Gate::authorize('update certificate');

        abort_unless($template->event_id === $event->id, 404);
        $this->persistTemplate($request, $event, $template, $request->validated());

        return response()->json([
            'message' => 'Certificate template updated successfully.',
            'template' => $template,
        ]);
    }

    public function preview(string $subdomain, Event $event, EventCertificateTemplate $template): Response
    {
        Gate::authorize('read certificate');
        abort_unless($template->event_id === $event->id, 404);

        $certificate = new EventCertificate([
            'recipient_name' => 'Akosua Élise Mensah',
            'recipient_email' => 'preview@example.test',
            'role' => $template->role,
            'cpd_hours' => $template->default_cpd_hours,
            'verification_code' => 'MC-PREVIEW-2026',
        ]);
        $certificate->setRelation('event', $event);
        $certificate->setRelation('template', $template);
        $certificate->setRelation('designVersion', null);
        $certificate->setRelation('registration', null);

        return $this->pdfs->generatePdf($certificate)->stream('certificate-preview.pdf');
    }

    public function artwork(string $subdomain, Event $event, EventCertificateTemplate $template, string $type): Response
    {
        Gate::authorize('read certificate');
        abort_unless($template->event_id === $event->id && in_array($type, ['background', 'signature'], true), 404);
        $disk = $template->getAttribute("{$type}_disk");
        $path = $template->getAttribute("{$type}_path");
        abort_unless(is_string($disk) && is_string($path), 404);
        $bytes = $this->artwork->contents($disk, $path);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        abort_unless(is_string($mime) && in_array($mime, ['image/png', 'image/jpeg'], true), 404);

        return response($bytes, 200, ['Content-Type' => $mime, 'Cache-Control' => 'private, max-age=300']);
    }

    public function issue(Request $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('issue certificates');
        $this->ensureDefaultTemplates($event);

        $validated = $request->validate([
            'target_group' => ['required', 'string', 'in:checked_in_delegates,all_delegates,speakers,presenters,custom'],
            'template_id' => ['nullable', 'uuid', Rule::exists('landlord.event_certificate_templates', 'id')->where('event_id', $event->id)],
            'role' => ['nullable', 'string', 'in:delegate,speaker,presenter,volunteer,custom'],
            'cpd_hours' => ['nullable', 'numeric', 'min:0'],
            'custom_recipients' => ['nullable', 'array'],
            'custom_recipients.*.name' => ['required_with:custom_recipients', 'string', 'max:255'],
            'custom_recipients.*.email' => ['required_with:custom_recipients', 'email', 'max:255'],
        ]);

        $targetGroup = $validated['target_group'];
        $role = $validated['role'] ?? match ($targetGroup) {
            'speakers' => EventCertificateTemplate::ROLE_SPEAKER,
            'presenters' => EventCertificateTemplate::ROLE_PRESENTER,
            default => EventCertificateTemplate::ROLE_DELEGATE,
        };

        $template = null;
        if (! empty($validated['template_id'])) {
            $template = $event->certificateTemplates()->find($validated['template_id']);
        }
        if ($template === null) {
            $template = $event->certificateTemplates()->where('role', $role)->first();
        }
        if (! $template instanceof EventCertificateTemplate) {
            $template = null;
        }

        if ($template?->design_mode === 'custom_background') {
            $this->requireBackground($template);
        }

        $cpdHours = isset($validated['cpd_hours']) && $validated['cpd_hours'] > 0
            ? (float) $validated['cpd_hours']
            : ($template?->default_cpd_hours ?? 0.0);

        $recipients = [];
        $missingRecipientCount = 0;

        if ($targetGroup === 'checked_in_delegates') {
            $regs = $event->registrations()->where('status', EventRegistration::STATUS_CHECKED_IN)->get();
            foreach ($regs as $reg) {
                $recipients[] = [
                    'name' => $reg->full_name ?? "{$reg->first_name} {$reg->last_name}",
                    'email' => $reg->email,
                    'registration_id' => $reg->id,
                    'user_id' => null,
                ];
            }
        } elseif ($targetGroup === 'all_delegates') {
            $regs = $event->registrations()->whereIn('status', [EventRegistration::STATUS_CONFIRMED, EventRegistration::STATUS_CHECKED_IN])->get();
            foreach ($regs as $reg) {
                $recipients[] = [
                    'name' => $reg->full_name ?? "{$reg->first_name} {$reg->last_name}",
                    'email' => $reg->email,
                    'registration_id' => $reg->id,
                    'user_id' => null,
                ];
            }
        } elseif ($targetGroup === 'speakers') {
            $speakers = $event->speakers()->get();
            foreach ($speakers as $speaker) {
                if (blank($speaker->email)) {
                    $missingRecipientCount++;

                    continue;
                }

                $recipients[] = [
                    'name' => $speaker->name,
                    'email' => mb_strtolower(mb_trim((string) $speaker->email)),
                    'registration_id' => null,
                    'user_id' => null,
                ];
            }
        } elseif ($targetGroup === 'presenters') {
            $abstracts = $event->abstracts()
                ->whereIn('status', [EventAbstract::STATUS_ACCEPTED_ORAL, EventAbstract::STATUS_ACCEPTED_POSTER])
                ->with('authors')
                ->get();

            foreach ($abstracts as $abs) {
                $primaryAuthor = $abs->authors->where('is_presenter', true)->first() ?? $abs->authors->first();
                if ($primaryAuthor) {
                    if (blank($primaryAuthor->email)) {
                        $missingRecipientCount++;

                        continue;
                    }

                    $recipients[] = [
                        'name' => "{$primaryAuthor->first_name} {$primaryAuthor->last_name}",
                        'email' => $primaryAuthor->email,
                        'registration_id' => null,
                        'user_id' => null,
                    ];
                }
            }
        } elseif ($targetGroup === 'custom' && ! empty($validated['custom_recipients'])) {
            foreach ($validated['custom_recipients'] as $custom) {
                $recipients[] = [
                    'name' => $custom['name'],
                    'email' => $custom['email'],
                    'registration_id' => null,
                    'user_id' => null,
                ];
            }
        }

        [$issuedCount, $skippedCount] = DB::connection('landlord')->transaction(
            function () use ($event, $recipients, $role, $template, $cpdHours): array {
                $issuedCount = 0;
                $skippedCount = 0;
                if ($template?->design_mode === 'custom_background') {
                    $template->update(['layout' => $this->layouts->resolveCertificate($template->layout ?? [])]);
                }
                $designVersion = $template !== null
                    ? $this->designVersions->snapshot($template)
                    : null;

                foreach ($recipients as $item) {
                    $exists = $event->certificates()
                        ->whereRaw('lower(recipient_email) = ?', [mb_strtolower($item['email'])])
                        ->where('role', $role)
                        ->exists();

                    if ($exists) {
                        $skippedCount++;

                        continue;
                    }

                    $event->certificates()->create([
                        'tenant_id' => $event->tenant_id,
                        'registration_id' => $item['registration_id'],
                        'user_id' => $item['user_id'],
                        'template_id' => $template?->id,
                        'design_version_id' => $designVersion?->id,
                        'recipient_name' => $item['name'],
                        'recipient_email' => mb_strtolower(mb_trim($item['email'])),
                        'role' => $role,
                        'cpd_hours' => $cpdHours,
                        'issued_at' => now(),
                    ]);

                    $issuedCount++;
                }

                return [$issuedCount, $skippedCount];
            },
            3,
        );

        return response()->json([
            'message' => "Successfully issued {$issuedCount} certificates. ({$skippedCount} skipped as already issued; {$missingRecipientCount} skipped without an email address)",
            'issued_count' => $issuedCount,
            'skipped_count' => $skippedCount,
            'missing_recipient_count' => $missingRecipientCount,
        ]);
    }

    public function download(Request $request, string $subdomain, Event $event, EventCertificate $certificate): Response
    {
        Gate::authorize('read certificate');
        abort_unless($certificate->event_id === $event->id, 404);

        $domPdf = $this->pdfs->generatePdf($certificate);
        $certificate->increment('download_count');

        return $domPdf->download("certificate-{$certificate->verification_code}.pdf");
    }

    public function destroy(Request $request, string $subdomain, Event $event, EventCertificate $certificate): JsonResponse
    {
        Gate::authorize('delete certificate');
        abort_unless($certificate->event_id === $event->id, 404);

        $certificate->delete();

        return response()->json([
            'message' => 'Certificate removed successfully.',
        ]);
    }

    private function ensureDefaultTemplates(Event $event): void
    {
        $roles = [
            EventCertificateTemplate::ROLE_DELEGATE,
            EventCertificateTemplate::ROLE_SPEAKER,
            EventCertificateTemplate::ROLE_PRESENTER,
            EventCertificateTemplate::ROLE_VOLUNTEER,
        ];

        foreach ($roles as $role) {
            $event->certificateTemplates()->firstOrCreate(
                [
                    'event_id' => $event->id,
                    'role' => $role,
                ],
                [
                    'tenant_id' => $event->tenant_id,
                    'title' => EventCertificateTemplate::defaultTitle($role),
                    'body_template' => EventCertificateTemplate::defaultBodyTemplate($role),
                    'issuer_name' => 'Academic Board & Convener',
                    'issuer_title' => 'Organizing Committee Chair',
                    'show_qr' => true,
                    'show_cpd_hours' => $role === EventCertificateTemplate::ROLE_DELEGATE,
                    'default_cpd_hours' => 6.0,
                ]
            );
        }
    }

    private function requireBackground(EventCertificateTemplate $template): void
    {
        try {
            if (! is_string($template->background_disk) || ! is_string($template->background_path)) {
                throw new RuntimeException('Missing background.');
            }
            $this->artwork->dataUri($template->background_disk, $template->background_path);
            if (@getimagesizefromstring($this->artwork->contents($template->background_disk, $template->background_path)) === false) {
                throw new RuntimeException('Invalid background.');
            }
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['background' => 'Upload usable background artwork before issuing custom certificates.']);
        }
    }

    /** @param array<string, mixed> $validated */
    private function persistTemplate(
        StoreCertificateTemplateRequest|UpdateCertificateTemplateRequest $request,
        Event $event,
        EventCertificateTemplate $template,
        array $validated,
    ): void {
        $oldAssets = [
            'background' => [$template->background_disk, $template->background_path],
            'signature' => [$template->signature_disk, $template->signature_path],
        ];
        $newAssets = [];

        try {
            foreach (['background', 'signature'] as $type) {
                $file = $request->file($type);
                if ($file !== null) {
                    $stored = $this->artwork->store(
                        $file,
                        (string) $event->tenant_id,
                        (string) $event->id,
                        "certificate-{$type}",
                    );
                    $validated["{$type}_disk"] = $stored->disk;
                    $validated["{$type}_path"] = $stored->path;
                    $newAssets[] = [$stored->disk, $stored->path];
                } elseif ($request->boolean("remove_{$type}")) {
                    $validated["{$type}_disk"] = null;
                    $validated["{$type}_path"] = null;
                }
            }

            unset($validated['background'], $validated['signature'], $validated['remove_background'], $validated['remove_signature']);
            $validated['tenant_id'] = $event->tenant_id;
            $validated['event_id'] = $event->id;
            $validated['layout'] = $this->layouts->resolveCertificate($validated['layout'] ?? $template->layout ?? []);

            DB::connection('landlord')->transaction(function () use ($template, $validated): void {
                $template->fill($validated)->save();
            }, 3);
        } catch (Throwable $exception) {
            foreach ($newAssets as [$disk, $path]) {
                $this->artwork->delete($disk, $path);
            }

            throw $exception;
        }

        foreach ($oldAssets as $type => [$disk, $path]) {
            if (! is_string($disk) || ! is_string($path) || $path === $template->getAttribute("{$type}_path")) {
                continue;
            }

            $isRetained = $template->designVersions()->where("{$type}_disk", $disk)->where("{$type}_path", $path)->exists();
            if (! $isRetained) {
                $this->artwork->delete($disk, $path);
            }
        }
    }
}
