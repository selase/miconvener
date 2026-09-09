<?php

declare(strict_types=1);

namespace App\Services\Events;

use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Throwable;

final class QrCodeGenerator
{
    /**
     * Render the given payload as an inline SVG data URI. Suitable for the
     * confirmation page, where a browser renders SVG and a data URI without
     * complaint. Not suitable for email — see png().
     */
    public static function svgDataUri(string $payload, int $size = 240): string
    {
        $renderer = new ImageRenderer(new RendererStyle($size), new SvgImageBackEnd());
        $svg = (new Writer($renderer))->writeString($payload);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Render the given payload as raw PNG bytes, for embedding in an email as
     * an inline attachment. Mail clients are the reason this exists: Gmail
     * strips SVG and Outlook cannot render it at all, so a ticket sent as an
     * SVG data URI arrives as a broken image and the attendee reaches the door
     * with nothing to scan.
     *
     * Returns null when no raster backend is available, so the caller can fall
     * back rather than fail to send a ticket at all.
     */
    public static function png(string $payload, int $size = 240): ?string
    {
        if (! extension_loaded('imagick')) {
            return null;
        }

        try {
            $renderer = new ImageRenderer(new RendererStyle($size), new ImagickImageBackEnd());

            return (new Writer($renderer))->writeString($payload);
        } catch (Throwable) {
            return null;
        }
    }
}
