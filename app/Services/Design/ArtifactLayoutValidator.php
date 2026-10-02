<?php

declare(strict_types=1);

namespace App\Services\Design;

use Illuminate\Validation\ValidationException;

final class ArtifactLayoutValidator
{
    /** @var array<string, list<string>> */
    private const array ELEMENTS = [
        'certificate' => [
            'title', 'recipient_name', 'body', 'event_name', 'issuer', 'signature',
            'qr', 'verification_code', 'cpd_hours',
        ],
        'badge' => [
            'event_name', 'attendee_name', 'ticket_type', 'tier_label', 'ticket_code',
            'seat_label', 'qr', 'tenant_logo',
        ],
    ];

    /** @var list<string> */
    private const array FONTS = ['Helvetica', 'Times', 'Courier', 'DejaVu Sans'];

    /** @var list<string> */
    private const array ALIGNS = ['left', 'center', 'right'];

    /**
     * @param  array<string, mixed>  $layout
     * @return array<string, array<string, bool|float|int|string>>
     */
    public function validate(array $layout, string $artifact): array
    {
        $allowedElements = self::ELEMENTS[$artifact] ?? null;

        if ($allowedElements === null) {
            $this->invalid('Unknown artifact layout type.');
        }

        $canonical = [];

        foreach ($layout as $element => $settings) {
            if (! in_array($element, $allowedElements, true) || ! is_array($settings)) {
                $this->invalid("The {$element} layout element is not supported.");
            }

            $unknown = array_diff(array_keys($settings), [
                'x', 'y', 'width', 'height', 'align', 'font_family', 'font_size',
                'font_weight', 'color', 'visible',
            ]);

            if ($unknown !== []) {
                $this->invalid("The {$element} layout contains unsupported settings.");
            }

            $x = $this->number($settings, 'x', $element);
            $y = $this->number($settings, 'y', $element);
            $width = $this->number($settings, 'width', $element);
            $height = $this->number($settings, 'height', $element);

            if ($x < 0 || $y < 0 || $width <= 0 || $height <= 0 || $x + $width > 1 || $y + $height > 1) {
                $this->invalid("The {$element} layout must remain inside the page.");
            }

            $font = $settings['font_family'] ?? 'Helvetica';
            $align = $settings['align'] ?? 'center';
            $fontSize = $settings['font_size'] ?? 16;
            $fontWeight = $settings['font_weight'] ?? 400;
            $color = mb_strtoupper((string) ($settings['color'] ?? '#111827'));

            if (! is_string($font) || ! in_array($font, self::FONTS, true)) {
                $this->invalid("The {$element} font is not supported.");
            }

            if (! is_string($align) || ! in_array($align, self::ALIGNS, true)) {
                $this->invalid("The {$element} alignment is not supported.");
            }

            if (! is_numeric($fontSize) || (float) $fontSize < 6 || (float) $fontSize > 96) {
                $this->invalid("The {$element} font size must be between 6 and 96 points.");
            }

            if (! in_array((int) $fontWeight, [400, 500, 600, 700, 800], true)) {
                $this->invalid("The {$element} font weight is not supported.");
            }

            if (preg_match('/\A#[0-9A-F]{6}\z/', $color) !== 1) {
                $this->invalid("The {$element} color must be a six-digit hex color.");
            }

            $canonical[$element] = [
                'x' => $x,
                'y' => $y,
                'width' => $width,
                'height' => $height,
                'align' => $align,
                'font_family' => $font,
                'font_size' => (float) $fontSize,
                'font_weight' => (int) $fontWeight,
                'color' => $color,
                'visible' => (bool) ($settings['visible'] ?? true),
            ];
        }

        return $canonical;
    }

    /** @param array<string, mixed> $settings */
    private function number(array $settings, string $key, string $element): float
    {
        if (! array_key_exists($key, $settings) || ! is_numeric($settings[$key])) {
            $this->invalid("The {$element} {$key} value is required and must be numeric.");
        }

        return (float) $settings[$key];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['layout' => $message]);
    }
}
