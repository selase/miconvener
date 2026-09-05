<?php

declare(strict_types=1);

namespace App\Services\Events;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

final class QrCodeGenerator
{
    /**
     * Render the given payload as an inline SVG data URI, suitable for an
     * <img src="..."> in both the confirmation page and the ticket email.
     */
    public static function svgDataUri(string $payload, int $size = 240): string
    {
        $renderer = new ImageRenderer(new RendererStyle($size), new SvgImageBackEnd());
        $svg = (new Writer($renderer))->writeString($payload);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
