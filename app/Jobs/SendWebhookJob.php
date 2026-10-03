<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookCall;
use App\Models\WebhookEndpoint;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

final class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    public function __construct(
        public WebhookEndpoint $endpoint,
        public string $event,
        public array $payload,
        public ?string $existingCallId = null
    ) {}

    public function handle(): WebhookCall
    {
        $payloadJson = json_encode($this->payload);
        $signature = hash_hmac('sha256', (string) $payloadJson, (string) $this->endpoint->secret);

        $call = $this->existingCallId
            ? $this->endpoint->calls()->find($this->existingCallId)
            : null;

        if (! $call) {
            $call = WebhookCall::create([
                'tenant_id' => $this->endpoint->tenant_id,
                'webhook_endpoint_id' => $this->endpoint->id,
                'event_name' => $this->event,
                'payload' => $this->payload,
                'status' => null,
            ]);
        }

        if (! app()->environment('local', 'testing')) {
            $host = parse_url($this->endpoint->url, PHP_URL_HOST);
            if ($host) {
                $ip = gethostbyname($host);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    $call->update([
                        'status' => 400,
                        'duration_ms' => 1,
                        'exception' => 'Blocked request to internal, private, or reserved IP address.',
                    ]);

                    return $call;
                }
            }
        }

        $startTime = hrtime(true);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'MiConvener-Webhook-Dispatcher/1.0',
                    'X-MiConvener-Signature' => $signature,
                    'X-MiConvener-Event' => $this->event,
                    'X-MiConvener-Delivery' => $call->id,
                    'X-Tenant-Signature' => $signature,
                    'X-Tenant-Event' => $this->event,
                ])
                ->post($this->endpoint->url, $this->payload);

            $durationMs = (int) max(1, round((hrtime(true) - $startTime) / 1e6));

            $call->update([
                'status' => $response->status(),
                'duration_ms' => $durationMs,
                'response' => mb_substr($response->body(), 0, 65000),
                'exception' => null,
            ]);
        } catch (Exception $e) {
            $durationMs = (int) max(1, round((hrtime(true) - $startTime) / 1e6));

            $call->update([
                'status' => 500,
                'duration_ms' => $durationMs,
                'response' => null,
                'exception' => mb_substr($e->getMessage(), 0, 65000),
            ]);
        }

        return $call;
    }
}
