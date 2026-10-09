<?php

declare(strict_types=1);

namespace App\Services\Badges;

use App\Models\Event;
use App\Models\EventBadgeTemplate;
use App\Models\EventRegistration;
use App\Services\Design\ArtifactArtworkService;
use App\Services\Design\ArtifactLayoutValidator;
use App\Services\Events\QrCodeGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPDF;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class BadgePdfService
{
    public function __construct(
        private readonly ArtifactArtworkService $artwork,
        private readonly BadgeTemplateService $templates,
        private readonly ArtifactLayoutValidator $layouts,
    ) {}

    /** @param Collection<int, EventRegistration> $registrations */
    public function generate(Event $event, EventBadgeTemplate $template, Collection $registrations): DomPDF
    {
        $paper = $template->sheetSettings()['paper'] === 'letter' ? 'letter' : 'a4';

        return Pdf::loadHTML($this->renderHtml($event, $template, $registrations))
            ->setPaper($paper, 'portrait')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', false);
    }

    /** @param Collection<int, EventRegistration> $registrations */
    public function renderHtml(Event $event, EventBadgeTemplate $template, Collection $registrations): string
    {
        $settings = $template->sheetSettings();
        $paper = $settings['paper'] === 'letter' ? [215.9, 279.4] : [210.0, 297.0];
        $margin = $settings['margin_mm'];
        $gap = $settings['gap_mm'];
        if ($settings['crop_marks'] && $gap < 3) {
            throw ValidationException::withMessages(['sheet_settings.gap_mm' => 'Crop marks require at least 3 mm between badges. Increase the gap or turn crop marks off.']);
        }
        $columns = (int) floor(($paper[0] - (2 * $margin) + $gap) / ($template->width_mm + $gap));
        $rows = (int) floor(($paper[1] - (2 * $margin) + $gap) / ($template->height_mm + $gap));

        if ($columns < 1 || $rows < 1) {
            throw ValidationException::withMessages(['sheet_settings' => 'The badge dimensions do not fit on the selected paper.']);
        }

        $background = null;
        if (is_string($template->background_disk) && is_string($template->background_path)) {
            try {
                $background = $this->artwork->dataUri($template->background_disk, $template->background_path);
            } catch (RuntimeException) {
                report('Badge artwork is unavailable; the MiConvener fallback was used.');
            }
        }

        $badges = $registrations->map(function (EventRegistration $registration) use ($event, $template): array {
            if (mb_strlen($registration->full_name) > 80) {
                throw ValidationException::withMessages(['registration_ids' => "{$registration->full_name} is too long for the badge layout."]);
            }

            $tier = $registration->ticketType->badge_tier ?? 'general';

            $values = [
                'event_name' => $event->name,
                'attendee_name' => $registration->full_name,
                'ticket_type' => $registration->ticketType->name ?? '',
                'tier_label' => mb_strtoupper($tier),
                'ticket_code' => $registration->ticket_code ?? '',
                'seat_label' => $registration->seatAssignment->seat_label ?? '',
            ];
            $layout = $template->layoutSettings();
            foreach ($values as $key => $value) {
                if (isset($layout[$key])) {
                    $layout[$key] = $this->layouts->fitText($value, $layout[$key], $layout[$key]['width'] * $template->width_mm, $layout[$key]['height'] * $template->height_mm, 'layout.'.$key);
                }
            }

            return [
                'values' => $values,
                'layout' => $layout,
                'qr' => $registration->qr_token ? QrCodeGenerator::svgDataUri($registration->qr_token, 240) : null,
                'tier_style' => $template->tierStyleSettings()[$tier] ?? [],
            ];
        });

        return view('pdf.badge-sheet', [
            'template' => $template,
            'layout' => $template->layoutSettings(),
            'pages' => $badges->chunk($columns * $rows),
            'backgroundDataUri' => $background,
            'backgroundBox' => $this->backgroundBox($template, $background),
            'tenantLogoDataUri' => $this->templates->tenantLogo($event),
            'margin' => $margin,
            'gap' => $gap,
            'columns' => $columns,
            'paperWidth' => $paper[0],
            'paperHeight' => $paper[1],
            'cropMarks' => $settings['crop_marks'],
        ])->render();
    }

    /** @return array{x: float, y: float, width: float, height: float} */
    private function backgroundBox(EventBadgeTemplate $template, ?string $background): array
    {
        $width = (float) $template->width_mm;
        $height = (float) $template->height_mm;
        $settings = $template->backgroundSettings();
        $image = $background ? getimagesizefromstring(base64_decode(explode(',', $background, 2)[1], true) ?: '') : false;
        if ($settings['fit'] === 'stretch' || $image === false) {
            return ['x' => 0.0, 'y' => 0.0, 'width' => $width, 'height' => $height];
        }
        $scale = $settings['fit'] === 'contain' ? min($width / $image[0], $height / $image[1]) : max($width / $image[0], $height / $image[1]);
        $fittedWidth = $image[0] * $scale;
        $fittedHeight = $image[1] * $scale;
        $position = $settings['position'];
        $horizontal = str_contains($position, 'left') ? 0.0 : (str_contains($position, 'right') ? 1.0 : 0.5);
        $vertical = str_contains($position, 'top') ? 0.0 : (str_contains($position, 'bottom') ? 1.0 : 0.5);

        return ['x' => ($width - $fittedWidth) * $horizontal, 'y' => ($height - $fittedHeight) * $vertical, 'width' => $fittedWidth, 'height' => $fittedHeight];
    }
}
