<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventAbstract;
use App\Models\EventCertificate;
use App\Models\EventCertificateTemplate;
use App\Models\EventRegistration;
use App\Services\Certificates\CertificateDesignVersionService;
use App\Services\Certificates\CertificatePdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class EventCertificateController extends Controller
{
    public function __construct(
        private readonly CertificatePdfService $pdfs,
        private readonly CertificateDesignVersionService $designVersions,
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
            'certificates' => $certificates,
            'stats' => $stats,
            'eligible' => $eligible,
        ]);
    }

    public function storeTemplate(Request $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('create certificate');

        $validated = $request->validate([
            'role' => ['required', 'string', 'in:delegate,speaker,presenter,volunteer,custom'],
            'title' => ['required', 'string', 'max:255'],
            'body_template' => ['nullable', 'string'],
            'issuer_name' => ['nullable', 'string', 'max:255'],
            'issuer_title' => ['nullable', 'string', 'max:255'],
            'show_qr' => ['nullable', 'boolean'],
            'show_cpd_hours' => ['nullable', 'boolean'],
            'default_cpd_hours' => ['nullable', 'numeric', 'min:0'],
        ]);

        $template = $event->certificateTemplates()->updateOrCreate(
            [
                'event_id' => $event->id,
                'role' => $validated['role'],
            ],
            [
                'tenant_id' => $event->tenant_id,
                'title' => $validated['title'],
                'body_template' => $validated['body_template'] ?? EventCertificateTemplate::defaultBodyTemplate($validated['role']),
                'issuer_name' => $validated['issuer_name'] ?? null,
                'issuer_title' => $validated['issuer_title'] ?? null,
                'show_qr' => $validated['show_qr'] ?? true,
                'show_cpd_hours' => $validated['show_cpd_hours'] ?? false,
                'default_cpd_hours' => (float) ($validated['default_cpd_hours'] ?? 0.0),
            ]
        );

        return response()->json([
            'message' => 'Certificate template saved successfully.',
            'template' => $template,
        ]);
    }

    public function updateTemplate(Request $request, string $subdomain, Event $event, EventCertificateTemplate $template): JsonResponse
    {
        Gate::authorize('update certificate');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body_template' => ['nullable', 'string'],
            'issuer_name' => ['nullable', 'string', 'max:255'],
            'issuer_title' => ['nullable', 'string', 'max:255'],
            'show_qr' => ['nullable', 'boolean'],
            'show_cpd_hours' => ['nullable', 'boolean'],
            'default_cpd_hours' => ['nullable', 'numeric', 'min:0'],
        ]);

        $template->update($validated);

        return response()->json([
            'message' => 'Certificate template updated successfully.',
            'template' => $template,
        ]);
    }

    public function issue(Request $request, string $subdomain, Event $event): JsonResponse
    {
        Gate::authorize('issue certificates');
        $this->ensureDefaultTemplates($event);

        $validated = $request->validate([
            'target_group' => ['required', 'string', 'in:checked_in_delegates,all_delegates,speakers,presenters,custom'],
            'template_id' => ['nullable', 'uuid', 'exists:landlord.event_certificate_templates,id'],
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

        $domPdf = $this->pdfs->generatePdf($certificate);
        $certificate->increment('download_count');

        return $domPdf->download("certificate-{$certificate->verification_code}.pdf");
    }

    public function destroy(Request $request, string $subdomain, Event $event, EventCertificate $certificate): JsonResponse
    {
        Gate::authorize('delete certificate');

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
}
