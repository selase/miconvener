<?php

declare(strict_types=1);

namespace App\Services\Badges;

use App\Models\Event;
use App\Models\EventBadgeTemplate;

final class BadgeTemplateService
{
    public function forEvent(Event $event): EventBadgeTemplate
    {
        $template = EventBadgeTemplate::query()->firstOrCreate(
            ['event_id' => $event->id],
            $this->defaults($event),
        );

        return $template;
    }

    /** @return array<string, mixed> */
    public function defaults(Event $event): array
    {
        return [
            'tenant_id' => $event->tenant_id,
            'width_mm' => 100,
            'height_mm' => 70,
            'orientation' => 'landscape',
            'layout' => [
                'event_name' => $this->element(0.08, 0.08, 0.84, 0.10, 11, 700),
                'attendee_name' => $this->element(0.08, 0.30, 0.84, 0.20, 22, 700),
                'ticket_type' => $this->element(0.08, 0.53, 0.54, 0.08, 10, 600),
                'tier_label' => $this->element(0.08, 0.64, 0.54, 0.08, 10, 700),
                'ticket_code' => $this->element(0.08, 0.79, 0.54, 0.07, 8, 400),
                'seat_label' => $this->element(0.08, 0.88, 0.54, 0.06, 8, 600),
                'qr' => $this->element(0.68, 0.58, 0.24, 0.34, 8, 400),
            ],
            'tier_styles' => [],
            'sheet_settings' => [
                'paper' => 'a4', 'margin_mm' => 8, 'gap_mm' => 3, 'crop_marks' => true,
            ],
        ];
    }

    /** @return array<string, bool|float|int|string> */
    private function element(float $x, float $y, float $width, float $height, float $fontSize, int $fontWeight): array
    {
        return compact('x', 'y', 'width', 'height') + [
            'align' => 'center', 'font_family' => 'Helvetica', 'font_size' => $fontSize,
            'font_weight' => $fontWeight, 'color' => '#111827', 'visible' => true,
        ];
    }
}
