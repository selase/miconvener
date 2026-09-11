<?php

declare(strict_types=1);

namespace App\Services\Certificates;

use App\Models\EventCertificate;
use App\Models\EventCertificateTemplate;
use App\Services\Events\QrCodeGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPDF;

final class CertificatePdfService
{
    public function formatBody(EventCertificate $certificate, ?EventCertificateTemplate $template): string
    {
        $rawTemplate = $template?->body_template
            ?? EventCertificateTemplate::defaultBodyTemplate($certificate->role);

        $dateFormatted = $certificate->event->starts_at
            ? $certificate->event->starts_at->format('F j, Y')
            : date('F j, Y');

        if ($certificate->event->starts_at && $certificate->event->ends_at && ! $certificate->event->starts_at->isSameDay($certificate->event->ends_at)) {
            $dateFormatted .= ' to '.$certificate->event->ends_at->format('F j, Y');
        }

        $hours = $certificate->cpd_hours > 0
            ? (string) number_format($certificate->cpd_hours, 1)
            : ($template?->default_cpd_hours ? (string) number_format($template->default_cpd_hours, 1) : '');

        $replacements = [
            '{name}' => $certificate->recipient_name,
            '{event_name}' => $certificate->event->name,
            '{date}' => $dateFormatted,
            '{role}' => ucfirst(str_replace('_', ' ', $certificate->role)),
            '{hours}' => $hours,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $rawTemplate);
    }

    public function generatePdf(EventCertificate $certificate): DomPDF
    {
        $certificate->loadMissing(['event.tenant', 'template', 'registration']);

        $event = $certificate->event;
        $template = $certificate->template;

        $bodyText = $this->formatBody($certificate, $template);
        $verificationUrl = $certificate->verificationUrl();

        $qrDataUri = null;
        if ($template === null || $template->show_qr) {
            $png = QrCodeGenerator::png($verificationUrl, 240);
            $qrDataUri = $png !== null
                ? 'data:image/png;base64,'.base64_encode($png)
                : QrCodeGenerator::svgDataUri($verificationUrl, 240);
        }

        return Pdf::loadView('pdf.certificate-template', [
            'certificate' => $certificate,
            'event' => $event,
            'template' => $template,
            'bodyText' => $bodyText,
            'qrDataUri' => $qrDataUri,
            'verificationUrl' => $verificationUrl,
        ])
            ->setPaper('a4', 'landscape')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', true);
    }

    public function outputPdf(EventCertificate $certificate): string
    {
        return $this->generatePdf($certificate)->output();
    }
}
