<?php

declare(strict_types=1);

namespace App\Services\Certificates;

use App\Models\EventCertificate;
use App\Models\EventCertificateDesignVersion;
use App\Models\EventCertificateTemplate;
use App\Services\Design\ArtifactArtworkService;
use App\Services\Design\ArtifactLayoutValidator;
use App\Services\Events\QrCodeGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPDF;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CertificatePdfService
{
    public function __construct(
        private readonly ArtifactArtworkService $artwork,
        private readonly ArtifactLayoutValidator $layouts,
    ) {}

    public function formatBody(
        EventCertificate $certificate,
        EventCertificateTemplate|EventCertificateDesignVersion|null $design,
    ): string {
        $rawTemplate = $design?->body_template
            ?? EventCertificateTemplate::defaultBodyTemplate($certificate->role);

        $dateFormatted = $certificate->event->starts_at
            ? $certificate->event->starts_at->format('F j, Y')
            : date('F j, Y');

        if ($certificate->event->starts_at && $certificate->event->ends_at && ! $certificate->event->starts_at->isSameDay($certificate->event->ends_at)) {
            $dateFormatted .= ' to '.$certificate->event->ends_at->format('F j, Y');
        }

        $hours = $certificate->cpd_hours > 0
            ? (string) number_format($certificate->cpd_hours, 1)
            : ($design?->default_cpd_hours ? (string) number_format($design->default_cpd_hours, 1) : '');

        $replacements = [
            '{name}' => $certificate->recipient_name,
            '{event_name}' => $certificate->event->name,
            '{date}' => $dateFormatted,
            '{role}' => ucfirst(str_replace('_', ' ', $certificate->role)),
            '{hours}' => $hours,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $rawTemplate);
    }

    /** @param array<string, string|null> $previewAssets */
    public function generatePdf(EventCertificate $certificate, array $previewAssets = []): DomPDF
    {
        return Pdf::loadHTML($this->renderHtml($certificate, $previewAssets))
            ->setPaper('a4', 'landscape')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', false);
    }

    /** @param array<string, string|null> $previewAssets */
    public function renderHtml(EventCertificate $certificate, array $previewAssets = []): string
    {
        $certificate->loadMissing(['event.tenant', 'template', 'designVersion', 'registration']);

        $event = $certificate->event;
        $design = $certificate->designVersion ?? $certificate->template;

        $bodyText = $this->formatBody($certificate, $design);
        $verificationUrl = $certificate->verificationUrl();

        $qrDataUri = null;
        if ($design === null || $design->show_qr) {
            $png = QrCodeGenerator::png($verificationUrl, 240);
            $qrDataUri = $png !== null
                ? 'data:image/png;base64,'.base64_encode($png)
                : QrCodeGenerator::svgDataUri($verificationUrl, 240);
        }

        $signatureDataUri = array_key_exists('signature', $previewAssets) ? $previewAssets['signature'] : $this->assetDataUri($certificate, $design, 'signature');
        $view = 'pdf.certificate-template';
        $layout = [];
        $backgroundDataUri = null;

        if ($design?->design_mode === 'custom_background') {
            if (mb_strlen($certificate->recipient_name) > 80) {
                throw ValidationException::withMessages([
                    'recipient_name' => 'Recipient name is too long for the certificate layout.',
                ]);
            }

            $layout = $certificate->designVersion !== null
                ? $this->layouts->validate($design->layout ?? [], 'certificate')
                : $this->layouts->resolveCertificate($design->layout ?? []);
            $layout['verification_code'] = ($layout['verification_code'] ?? [
                'x' => 0.68,
                'y' => 0.9,
                'width' => 0.25,
                'height' => 0.04,
                'align' => 'right',
                'font_family' => 'Courier',
                'font_size' => 9.0,
                'font_weight' => 700,
                'color' => '#111827',
            ]) + ['visible' => true];
            $layout['verification_code']['visible'] = true;

            if ($signatureDataUri !== null && ! isset($layout['signature'])) {
                $layout['signature'] = $this->defaultElement(0.10, 0.80, 0.22, 0.08, 'left');
            }

            if ($qrDataUri !== null && ! isset($layout['qr'])) {
                $layout['qr'] = $this->defaultElement(0.82, 0.75, 0.10, 0.14, 'center');
            }

            if ($design->show_cpd_hours && ! isset($layout['cpd_hours'])) {
                $layout['cpd_hours'] = $this->defaultElement(0.25, 0.68, 0.50, 0.05, 'center');
            }

            $backgroundDataUri = array_key_exists('background', $previewAssets) ? $previewAssets['background'] : $this->assetDataUri($certificate, $design, 'background', true);
            $view = 'pdf.certificate-custom';
        }

        $elementValues = $this->elementValues($certificate, $design, $bodyText);
        $defaultTextSizes = [];
        if ($view === 'pdf.certificate-custom') {
            foreach ($elementValues as $key => $value) {
                if (! isset($layout[$key]) || ($key === 'cpd_hours' && (! $design->show_cpd_hours || $certificate->cpd_hours <= 0))) {
                    continue;
                }
                $layout[$key] = $this->layouts->fitText($value, $layout[$key], $layout[$key]['width'] * 297, $layout[$key]['height'] * 210, 'layout.'.$key);
            }
        } else {
            foreach (['title' => [28, 700, 235, 24], 'recipient_name' => [32, 700, 235, 22], 'body' => [14, 400, 180, 36], 'issuer' => [11, 400, 85, 15]] as $key => [$size, $weight, $width, $height]) {
                $settings = $this->layouts->fitText($elementValues[$key], ['font_family' => 'DejaVu Sans', 'font_weight' => $weight, 'font_size' => $size], $width, $height, $key, $key === 'body' ? 1.65 : 1.2);
                $defaultTextSizes[$key] = $settings['font_size'];
            }
        }

        return view($view, [
            'previewWarning' => $previewAssets['warning'] ?? null,
            'certificate' => $certificate,
            'event' => $event,
            'template' => $design,
            'bodyText' => $bodyText,
            'qrDataUri' => $qrDataUri,
            'verificationUrl' => $verificationUrl,
            'signatureDataUri' => $signatureDataUri,
            'backgroundDataUri' => $backgroundDataUri,
            'layout' => $layout,
            'elementValues' => $elementValues,
            'defaultTextSizes' => $defaultTextSizes,
        ])->render();
    }

    public function outputPdf(EventCertificate $certificate): string
    {
        return $this->generatePdf($certificate)->output();
    }

    private function assetDataUri(
        EventCertificate $certificate,
        EventCertificateTemplate|EventCertificateDesignVersion|null $design,
        string $type,
        bool $required = false,
    ): ?string {
        $disk = $design?->getAttribute("{$type}_disk");
        $path = $design?->getAttribute("{$type}_path");

        if (! is_string($disk) || ! is_string($path)) {
            if ($required && $certificate->designVersion !== null) {
                throw new HttpException(503, 'Issued certificate artwork is unavailable. Contact the organizer to restore the original artwork.');
            }

            return null;
        }

        try {
            return $this->artwork->dataUri($disk, $path);
        } catch (RuntimeException $exception) {
            if ($certificate->designVersion !== null) {
                throw new HttpException(503, 'Issued certificate artwork is unavailable. Contact the organizer to restore the original artwork.', $exception);
            }

            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function elementValues(
        EventCertificate $certificate,
        EventCertificateTemplate|EventCertificateDesignVersion|null $design,
        string $bodyText,
    ): array {
        return [
            'title' => $design->title ?? 'Certificate of Participation',
            'recipient_name' => $certificate->recipient_name,
            'body' => $bodyText,
            'event_name' => $certificate->event->name,
            'issuer' => mb_trim(implode(' · ', array_filter([$design?->issuer_name, $design?->issuer_title]))),
            'verification_code' => $certificate->verification_code,
            'cpd_hours' => number_format((float) $certificate->cpd_hours, 1).' Continuing Education (CPD/CME) Contact Hours',
        ];
    }

    /**
     * @return array<string, bool|float|int|string>
     */
    private function defaultElement(float $x, float $y, float $width, float $height, string $align): array
    {
        return [
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
            'align' => $align,
            'font_family' => 'Helvetica',
            'font_size' => 11.0,
            'font_weight' => 600,
            'color' => '#111827',
            'visible' => true,
        ];
    }
}
