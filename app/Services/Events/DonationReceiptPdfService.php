<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\EventContribution;
use Barryvdh\DomPDF\Facade\Pdf;

final class DonationReceiptPdfService
{
    /**
     * Generate an official PDF donation/tax receipt for an event contribution.
     */
    public function generate(EventContribution $contribution): string
    {
        $contribution->loadMissing(['event' => fn ($query) => $query->withoutGlobalScopes(), 'tenant']);
        $link = \App\Models\GivingContributionLink::withoutGlobalScopes()->where('tenant_id', $contribution->tenant_id)->where('event_contribution_id', $contribution->id)->where('origin', 'checkout')->where('category', 'giving')->first();
        if ($link !== null) {
            $source = app(\App\Services\Giving\GivingSources::class)->find($contribution->tenant, 'event_contribution', $contribution->id);
            $adjustments = \App\Models\GivingAdjustment::withoutGlobalScopes()->where('tenant_id', $contribution->tenant_id)->where('event_contribution_id', $contribution->id)->where('status', 'confirmed')->orderBy('confirmed_at')->get();

            return Pdf::loadView('pdf.giving-receipt', ['receipt' => app(\App\Services\Giving\GivingHistory::class)->originalReceipt($source), 'adjustments' => $adjustments])->setPaper('a4', 'portrait')->output();
        }

        $event = $contribution->event;
        $tenant = $contribution->tenant;

        return Pdf::loadView('pdf.donation-receipt', [
            'contribution' => $contribution,
            'event' => $event,
            'tenant' => $tenant,
            'brandDataUri' => $this->fileDataUri(public_path('assets/img/brand/miconvener.png')),
        ])->setPaper('a4', 'portrait')->output();
    }

    /**
     * Get a standardized receipt filename.
     */
    public function filename(EventContribution $contribution): string
    {
        $ref = str($contribution->payment_reference)->slug()->toString() ?: (string) $contribution->id;

        return "receipt-{$ref}.pdf";
    }

    private function fileDataUri(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
    }
}
