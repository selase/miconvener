<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\InvoicingService;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;

final class GenerateMonthlyInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:generate-invoices {--month= : The YYYY-MM to bill for (defaults to last month)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate monthly invoices for all tenants based on usage rollups';

    /**
     * Execute the console command.
     */
    public function handle(InvoicingService $invoicingService): void
    {
        $monthStr = $this->option('month') ?: now()->subMonth()->format('Y-m');
        $start = Carbon::parse($monthStr)->startOfMonth();
        $end = Carbon::parse($monthStr)->endOfMonth();

        $this->info("Generating invoices for period: {$start->toDateString()} to {$end->toDateString()}");

        $tenants = Tenant::where('status', 'active')->get();
        $count = 0;

        foreach ($tenants as $tenant) {
            try {
                $invoice = $invoicingService->generate($tenant, $start, $end);
                $this->line(" - Generated Invoice {$invoice->number} for {$tenant->name} (Total: {$invoice->total})");
                $count++;
            } catch (Exception $e) {
                $this->error("Failed to generate invoice for {$tenant->name}: {$e->getMessage()}");
            }
        }

        $this->info("Invoicing completed. {$count} invoices processed.");
    }
}
