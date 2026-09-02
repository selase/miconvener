<?php

declare(strict_types=1);

use App\Events\InvoiceIssued;
use App\Jobs\SendWebhookJob;
use App\Listeners\WebhookEventSubscriber;
use App\Models\Invoice;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Queue;

it('dispatches webhook job when invoice is issued and endpoint is active', function () {
    Queue::fake();

    $tenant = setActiveTenantForTest();

    $endpoint = WebhookEndpoint::factory()->create([
        'tenant_id' => $tenant->id,
        'url' => 'https://example.com/webhook',
        'events' => ['invoice.issued'],
        'is_active' => true,
    ]);

    $invoice = Invoice::factory()->issued()->create([
        'tenant_id' => $tenant->id,
    ]);

    $event = new InvoiceIssued($invoice);

    $subscriber = new WebhookEventSubscriber();
    $subscriber->handleInvoiceIssued($event);

    Queue::assertPushed(SendWebhookJob::class, function (SendWebhookJob $job) use ($endpoint, $invoice) {
        return $job->endpoint->id === $endpoint->id
            && $job->event === 'invoice.issued'
            && $job->payload['invoice_id'] === $invoice->number
            && $job->payload['amount'] === $invoice->total
            && $job->payload['status'] === $invoice->status;
    });
});
