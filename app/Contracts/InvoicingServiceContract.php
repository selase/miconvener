<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Invoice;
use App\Models\Tenant;
use Carbon\CarbonInterface;

interface InvoicingServiceContract
{
    public function generate(Tenant $tenant, CarbonInterface $start, CarbonInterface $end): Invoice;

    public function issueInvoice(Invoice $invoice): Invoice;

    public function markPaid(Invoice $invoice): Invoice;

    public function voidInvoice(Invoice $invoice): Invoice;
}
