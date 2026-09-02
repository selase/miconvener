<?php

declare(strict_types=1);

namespace App\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public \App\Models\WebhookEndpoint $endpoint,
        public string $event,
        public array $payload
    ) {}

    public function handle(): void
    {
        $payloadJson = json_encode($this->payload);
        $signature = hash_hmac('sha256', $payloadJson, $this->endpoint->secret);

        $call = \App\Models\WebhookCall::create([
            'webhook_endpoint_id' => $this->endpoint->id,
            'event_name' => $this->event,
            'payload' => $this->payload,
            'status' => null,
        ]);

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Tenant-Signature' => $signature,
                    'X-Tenant-Event' => $this->event,
                ])
                ->post($this->endpoint->url, $this->payload);

            $call->update([
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            if ($response->failed()) {
                // Could handle retries here or let Queue handle it
                // throw new \Exception('Webhook failed with status: ' . $response->status());
            }

        } catch (Exception $e) {
            $call->update([
                'exception' => $e->getMessage(),
                'status' => 500, // Internal error representation
            ]);

            // Re-throw to trigger queue validation retry logic if desired
            // throw $e;
        }
    }
}
