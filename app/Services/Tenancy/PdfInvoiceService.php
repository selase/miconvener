<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

final class PdfInvoiceService
{
    /**
     * Generate a PDF instance for the given invoice.
     */
    public function generate(Invoice $invoice)
    {
        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice->load(['tenant', 'items']),
        ])->setPaper('a4');
    }

    /**
     * Stream the PDF to the browser.
     */
    public function stream(Invoice $invoice): Response
    {
        $pdf = $this->generate($invoice);
        $filename = "Invoice-{$invoice->number}.pdf";

        return $pdf->stream($filename);
    }

    /**
     * Download the PDF.
     */
    public function download(Invoice $invoice)
    {
        $pdf = $this->generate($invoice);
        $filename = "Invoice-{$invoice->number}.pdf";

        return $pdf->download($filename);
    }

    /**
     * Get the PDF content as a string (useful for email attachments).
     */
    public function output(Invoice $invoice): string
    {
        return $this->generate($invoice)->output();
    }
}
