<?php

declare(strict_types=1);

namespace App\Services\Badges;

use App\Models\EventBadgeTemplate;
use App\Services\Design\ArtifactArtworkService;
use App\Services\Design\ArtifactFontRegistry;
use App\Services\Design\ArtifactLayoutValidator;
use GdImage;
use Illuminate\Validation\ValidationException;

final class BadgeTemplatePreviewRenderer
{
    public function __construct(private readonly ArtifactArtworkService $artwork, private readonly ArtifactLayoutValidator $layouts, private readonly ArtifactFontRegistry $fonts, private readonly BadgePdfService $pdfs) {}

    /** @param array<string, mixed> $snapshot
     * @param  array<string, mixed>  $options
     */
    public function render(array $snapshot, bool $watermarked = true, array $options = []): string
    {
        if (isset($options['composition'], $snapshot['compositions'][$options['composition']])) {
            $snapshot = $snapshot['compositions'][$options['composition']];
        }
        $changedSize = isset($options['width_mm']) || isset($options['height_mm']);
        $snapshot['width_mm'] = $options['width_mm'] ?? $snapshot['width_mm'];
        $snapshot['height_mm'] = $options['height_mm'] ?? $snapshot['height_mm'];
        if (($options['side'] ?? 'front') === 'back') {
            if (! is_array($snapshot['back'] ?? null)) {
                throw ValidationException::withMessages(['side' => 'This design has no back face.']);
            }
            $snapshot = array_replace($snapshot, $snapshot['back']);
        }
        if ($changedSize) {
            $snapshot['background_settings']['fit'] = 'contain';
        }
        $template = new EventBadgeTemplate(array_intersect_key($snapshot, array_flip(BadgeLibraryService::FIELDS)));
        $width = 600;
        $height = (int) round($width * $template->height_mm / $template->width_mm);
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        if ($template->backgroundSettings()['visible'] && is_string($template->background_path)) {
            $uri = $this->artwork->dataUri($template->background_disk, $template->background_path);
            $box = $this->pdfs->backgroundBox($template, $uri);
            $this->drawImage($image, $uri, ['x' => $box['x'] / $template->width_mm, 'y' => $box['y'] / $template->height_mm,
                'width' => $box['width'] / $template->width_mm, 'height' => $box['height'] / $template->height_mm]);
        }
        foreach ($template->layoutSettings() as $key => $item) {
            if (($item['visible'] ?? true) === false || ($item['removed'] ?? false) === true) {
                continue;
            }
            if ($key === 'tenant_logo') {
                if ($template->logo_source === 'custom' && is_string($template->logo_path)) {
                    $this->drawImage($image, $this->artwork->dataUri($template->logo_disk, $template->logo_path), $item);
                }

                continue;
            }
            if ($key === 'qr') {
                $x = (int) ($item['x'] * $width);
                $y = (int) ($item['y'] * $height);
                $ink = imagecolorallocate($image, 100, 110, 120);
                imagerectangle($image, $x, $y, (int) ($x + $item['width'] * $width), (int) ($y + $item['height'] * $height), $ink);
                imagestring($image, 3, $x + 4, $y + 4, 'QR sample', $ink);

                continue;
            }
            $text = match ($key) {
                'attendee_name' => $options['sample_name'] ?? 'Sample Attendee', 'event_name' => 'Your conference',
                'ticket_type', 'tier_label' => $options['sample_category'] ?? 'Participant', 'ticket_code' => 'SAMPLE', 'seat_label' => 'A01', default => '',
            };
            if ($text !== '') {
                $fitted = $this->layouts->fitText($text, $item, $item['width'] * $template->width_mm, $item['height'] * $template->height_mm, 'preview.'.$key,
                    tenantId: $options['tenant_id'] ?? null, minimumFontSize: $key === 'attendee_name' ? 6.0 : null);
                $this->drawText($image, $text, $fitted, (float) $template->width_mm, $options['tenant_id'] ?? null);
            }
        }
        if ($watermarked) {
            $ink = imagecolorallocatealpha($image, 100, 40, 80, 45);
            for ($y = 80; $y < $height + 150; $y += 150) {
                imagettftext($image, 36, 25, 15, $y, $ink, base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf'), 'SAMPLE - GH50');
            }
        }
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        unset($image);

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    /** @param array<string, mixed> $box */
    private function drawImage(GdImage $target, string $uri, array $box): void
    {
        $bytes = base64_decode(explode(',', $uri, 2)[1], true);
        $source = $bytes === false ? false : imagecreatefromstring($bytes);
        if ($source !== false) {
            imagecopyresampled($target, $source, (int) ($box['x'] * imagesx($target)), (int) ($box['y'] * imagesy($target)), 0, 0,
                max(1, (int) ($box['width'] * imagesx($target))), max(1, (int) ($box['height'] * imagesy($target))), imagesx($source), imagesy($source));
            unset($source);
        }
    }

    /** @param array<string, bool|float|int|string> $item */
    private function drawText(GdImage $target, string $text, array $item, float $widthMm, ?string $tenantId): void
    {
        $family = match ($item['font_family']) {
            'Helvetica' => 'DejaVu Sans', 'Times' => 'DejaVu Serif', 'Courier' => 'DejaVu Sans Mono', default => $item['font_family'],
        };
        $font = $this->fonts->path($family, (int) $item['font_weight'] >= 600 ? 700 : 400, $tenantId);
        $size = (float) $item['font_size'] * imagesx($target) / ($widthMm * 72 / 25.4);
        $width = max(1, (int) ($item['width'] * imagesx($target)));
        $height = max(1, (int) ($item['height'] * imagesy($target)));
        $field = imagecreatetruecolor($width, $height);
        imagealphablending($field, false);
        imagefill($field, 0, 0, imagecolorallocatealpha($field, 255, 255, 255, 127));
        imagesavealpha($field, true);
        $rgb = sscanf((string) $item['color'], '#%02x%02x%02x');
        $ink = imagecolorallocate($field, $rgb[0], $rgb[1], $rgb[2]);
        $lines = [];
        foreach (explode("\n", $text) as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', mb_trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $candidate = $line === '' ? $word : $line.' '.$word;
                $box = imagettfbbox($size, 0, $font, $candidate);
                if ($line !== '' && $width < $box[2] - $box[0]) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $candidate;
                }
            }
            $lines[] = $line;
        }
        foreach ($lines as $number => $line) {
            $bounds = imagettfbbox($size, 0, $font, $line);
            $textWidth = $bounds[2] - $bounds[0];
            $x = (int) match ($item['align']) {
                'right' => $width - $textWidth, 'center' => ($width - $textWidth) / 2, default => 0
            };
            $y = (int) ($size + $number * $size * 1.15);
            imagettftext($field, $size, 0, $x, $y, $ink, $font, $line);
            if (($item['underline'] ?? false) === true) {
                imageline($field, $x, $y + 2, $x + $textWidth, $y + 2, $ink);
            }
        }
        imagecopy($target, $field, (int) ($item['x'] * imagesx($target)), (int) ($item['y'] * imagesy($target)), 0, 0, $width, $height);
        unset($field);
    }
}
