<?php

declare(strict_types=1);

namespace App\Services\Badges;

use App\Models\Event;
use App\Models\EventBadgeTemplate;
use App\Models\EventRegistration;
use App\Services\Design\ArtifactArtworkService;
use App\Services\Events\QrCodeGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPDF;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class BadgePdfService
{
    public function __construct(private readonly ArtifactArtworkService $artwork) {}

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

            return [
                'values' => [
                    'event_name' => $event->name,
                    'attendee_name' => $registration->full_name,
                    'ticket_type' => $registration->ticketType->name ?? '',
                    'tier_label' => mb_strtoupper($tier),
                    'ticket_code' => $registration->ticket_code ?? '',
                    'seat_label' => $registration->seatAssignment->seat_label ?? '',
                ],
                'qr' => $registration->qr_token ? QrCodeGenerator::svgDataUri($registration->qr_token, 240) : null,
                'tier_style' => $template->tierStyleSettings()[$tier] ?? [],
            ];
        });

        return view('pdf.badge-sheet', [
            'template' => $template,
            'layout' => $template->layoutSettings(),
            'pages' => $badges->chunk($columns * $rows),
            'backgroundDataUri' => $background,
            'margin' => $margin,
            'gap' => $gap,
            'columns' => $columns,
            'cropMarks' => $settings['crop_marks'],
        ])->render();
    }
}
