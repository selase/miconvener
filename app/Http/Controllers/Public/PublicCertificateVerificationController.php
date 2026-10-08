<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\EventCertificate;
use App\Services\Certificates\CertificatePdfService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class PublicCertificateVerificationController extends Controller
{
    public function verify(Request $request, string ...$params): Response
    {
        $uuid = end($params);

        $certificate = EventCertificate::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('uuid', $uuid)
            ->with(['event.tenant', 'template', 'designVersion'])
            ->first();

        if (! $certificate) {
            return Inertia::render('Public/Certificates/Verify', [
                'isValid' => false,
                'certificate' => null,
                'event' => null,
            ]);
        }

        $design = $certificate->designVersion ?? $certificate->template;

        return Inertia::render('Public/Certificates/Verify', [
            'isValid' => true,
            'certificate' => [
                'uuid' => $certificate->uuid,
                'verification_code' => $certificate->verification_code,
                'recipient_name' => $certificate->recipient_name,
                'recipient_email_masked' => $this->maskEmail($certificate->recipient_email),
                'role' => $certificate->role,
                'cpd_hours' => (float) $certificate->cpd_hours,
                'issued_at' => $certificate->issued_at?->format('F j, Y'),
                'title' => $design?->title ?? 'Certificate of Attendance',
                'issuer_name' => $design?->issuer_name ?? 'Organizing Committee',
                'issuer_title' => $design?->issuer_title ?? 'Convener',
            ],
            'event' => [
                'id' => $certificate->event->id,
                'name' => $certificate->event->name,
                'starts_at' => $certificate->event->starts_at?->format('F j, Y'),
                'ends_at' => $certificate->event->ends_at?->format('F j, Y'),
                'tenant_name' => $certificate->event->tenant?->name ?? 'MiConvener Host',
            ],
        ]);
    }

    public function download(Request $request, string ...$params): SymfonyResponse
    {
        $uuid = end($params);

        $certificate = EventCertificate::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('uuid', $uuid)
            ->with(['event.tenant', 'template'])
            ->firstOrFail();

        $pdfService = app(CertificatePdfService::class);
        $domPdf = $pdfService->generatePdf($certificate);
        $response = $domPdf->download("verified-certificate-{$certificate->verification_code}.pdf");
        $certificate->increment('download_count');

        return $response;
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***';
        }

        $name = $parts[0];
        $domain = $parts[1];

        $maskedName = mb_strlen($name) <= 2
            ? mb_substr($name, 0, 1).'***'
            : mb_substr($name, 0, 2).str_repeat('*', max(mb_strlen($name) - 3, 1)).mb_substr($name, -1);

        return $maskedName.'@'.$domain;
    }
}
