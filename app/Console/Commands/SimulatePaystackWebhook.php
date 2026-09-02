<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;

final class SimulatePaystackWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'paystack:simulate 
                            {event : The event to simulate (success, fail, disable)} 
                            {customerCode? : The customer code (e.g. CUS_123)} 
                            {subscriptionCode? : The subscription code (e.g. SUB_abc)}
                            {--url= : The webhook URL to target (defaults to config app.url)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate a Paystack webhook event to test subscription lifecycle';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $url = $this->option('url');
        $simulator = new \App\Services\Billing\PaystackSimulatorService($url);

        $event = $this->argument('event');
        $customerCode = $this->argument('customerCode') ?? 'CUS_'.str()->random(6);
        $subCode = $this->argument('subscriptionCode') ?? 'SUB_'.str()->random(6);

        $this->info("Simulating Paystack Webhook: {$event}");
        $this->line("Targeting Customer: {$customerCode}");
        $this->line("Targeting Subscription: {$subCode}");

        try {
            switch ($event) {
                case 'success':
                    $response = $simulator->simulateSuccessfulCharge($customerCode, $subCode);
                    break;
                case 'fail':
                    $response = $simulator->simulateFailedCharge($customerCode, $subCode);
                    break;
                case 'disable':
                    $response = $simulator->simulateSubscriptionDisabled($customerCode, $subCode);
                    break;
                default:
                    $this->error('Invalid event. Use success, fail, or disable.');

                    return Command::FAILURE;
            }

            if ($response->successful()) {
                $this->info('Webhook accepted by application (200 OK)!');
            } else {
                $this->error("Webhook failed with status: {$response->status()}");
                $this->error($response->body());
            }

        } catch (Exception $e) {
            $this->error('Error firing webhook: '.$e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
