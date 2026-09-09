<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\EventRegistration;
use Barryvdh\DomPDF\Facade\Pdf;

final class TicketPdfService
{
    /**
     * Render an attendee's ticket as a PDF.
     *
     * A venue is exactly where a phone runs out of battery or loses signal, so
     * the ticket has to survive without the network: the QR is embedded in the
     * file rather than fetched, and the entry code is printed beside it so the
     * door can fall back to a search by code.
     */
    public function generate(EventRegistration $registration): string
    {
        $registration->loadMissing(['event', 'tenant', 'ticketType']);

        return Pdf::loadView('pdf.ticket', [
            'registration' => $registration,
            'event' => $registration->event,
            'tenant' => $registration->tenant,
            // dompdf reads a base64 data URI directly, so the image travels
            // inside the PDF with no outbound request when it is opened.
            'qrDataUri' => $this->qrDataUri($registration->qr_token),
        ])->setPaper('a4')->output();
    }

    public function filename(EventRegistration $registration): string
    {
        $slug = str($registration->event->name)->slug()->limit(40, '')->toString();

        return "ticket-{$slug}-{$registration->ticket_code}.pdf";
    }

    private function qrDataUri(string $token): string
    {
        $png = QrCodeGenerator::png($token, 320);

        if ($png !== null) {
            return 'data:image/png;base64,'.base64_encode($png);
        }

        // dompdf cannot rasterise SVG reliably, but an unreadable box beats a
        // broken layout, and the entry code below it still gets them in.
        return QrCodeGenerator::svgDataUri($token, 320);
    }
}
