<?php

declare(strict_types=1);

namespace App\Services\Design;

use Dompdf\Dompdf;
use Dompdf\FontMetrics;
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

    private ?FontMetrics $fontMetrics = null;

    /**
     * @param  array<string, bool|float|int|string>  $settings
     * @return array<string, bool|float|int|string>
     */
    public function fitText(string $text, array $settings, float $widthMm, float $heightMm, string $field, float $lineHeight = 1.15): array
    {
        if ($text === '' || ($settings['visible'] ?? true) === false) {
            return $settings;
        }
        $metrics = $this->fontMetrics ??= (new Dompdf)->getFontMetrics();
        $font = $metrics->getFont((string) $settings['font_family'], (int) $settings['font_weight'] >= 600 ? 'bold' : 'normal')
            ?? $metrics->getFont('DejaVu Sans', 'normal');
        $width = $widthMm * 72 / 25.4;
        $height = $heightMm * 72 / 25.4;
        $original = (float) $settings['font_size'];
        $minimum = max(6.0, $original * 0.7);
        $size = $original;
        while (true) {
            $lines = 0;
            $fits = true;
            foreach (explode("\n", str_replace("\r", '', $text)) as $paragraph) {
                $lines++;
                $line = '';
                foreach (preg_split('/\s+/u', mb_trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                    if ($metrics->getTextWidth($word, $font, $size) > $width) {
                        $fits = false;
                        break 2;
                    }
                    $candidate = $line === '' ? $word : $line.' '.$word;
                    if ($line !== '' && $metrics->getTextWidth($candidate, $font, $size) > $width) {
                        $lines++;
                        $line = $word;
                    } else {
                        $line = $candidate;
                    }
                }
            }
            if ($fits && $lines * $size * $lineHeight <= $height) {
                $settings['font_size'] = $size;

                return $settings;
            }
            if ($size <= $minimum) {
                throw ValidationException::withMessages([$field => 'Text does not fit this field. Enlarge its box, reduce its font size, or shorten the text. Automatic reduction is limited to 30%.']);
            }
            $size = max($minimum, $size - 0.5);
        }
    }

    /** @return array<string, array<string, bool|float|int|string>> */
    public function certificateDefaults(): array
    {
        $boxes = [
            'title' => [0.15, 0.15, 0.70, 0.10, 28, 700],
            'recipient_name' => [0.15, 0.34, 0.70, 0.10, 32, 700],
            'body' => [0.15, 0.48, 0.70, 0.16, 16, 400],
            'event_name' => [0.15, 0.27, 0.70, 0.05, 14, 400],
            'issuer' => [0.10, 0.89, 0.50, 0.06, 11, 400],
            'signature' => [0.10, 0.79, 0.22, 0.08, 11, 400],
            'qr' => [0.82, 0.74, 0.10, 0.14, 11, 400],
            'verification_code' => [0.68, 0.90, 0.25, 0.04, 9, 700],
            'cpd_hours' => [0.15, 0.68, 0.60, 0.06, 11, 400],
        ];
        $layout = [];
        foreach ($boxes as $element => [$x, $y, $width, $height, $size, $weight]) {
            $layout[$element] = [
                'x' => $x, 'y' => $y, 'width' => $width, 'height' => $height,
                'align' => in_array($element, ['issuer', 'signature'], true) ? 'left' : ($element === 'verification_code' ? 'right' : 'center'),
                'font_family' => 'DejaVu Sans', 'font_size' => $size, 'font_weight' => $weight,
                'color' => '#111827', 'visible' => $element !== 'event_name',
            ];
        }

        return $this->validate($layout, 'certificate');
    }

    /**
     * Resolve editable designs only. Historical snapshots intentionally stay partial.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, array<string, bool|float|int|string>>
     */
    public function resolveCertificate(array $overrides = []): array
    {
        $layout = $this->validate(array_replace_recursive($this->certificateDefaults(), $overrides), 'certificate');
        $layout['recipient_name']['visible'] = true;
        $layout['verification_code']['visible'] = true;

        return $layout;
    }

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
