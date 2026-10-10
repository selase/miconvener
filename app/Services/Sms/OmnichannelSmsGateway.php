<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Sends through the Omnichannel messaging platform's v1 campaign API.
 *
 * Omnichannel queues every campaign and charges its own credit as it sends,
 * so a 202 here means "queued", not "delivered". It allows ten send requests
 * a minute per account, so a batch always goes as one request: identical
 * texts as a plain bulk message, different texts as the template "{{1}}"
 * with each recipient's own text as their placeholder. Omnichannel renders
 * each recipient's text and sends each distinct text separately to mNotify
 * (fixed in Omnichannel PR #206; before that it sent the first recipient's
 * text to everyone, so do not point this at an older Omnichannel).
 */
final class OmnichannelSmsGateway implements SmsGateway
{
    /** The placeholder that carries each recipient's own text. */
    public const string TEXT_PLACEHOLDER = '{{1}}';

    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly string $senderId,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            mb_rtrim((string) config('services.omnichannel.url'), '/'),
            (string) config('services.omnichannel.token'),
            (string) config('services.omnichannel.sender_id'),
        );
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->url !== '' && $this->senderId !== '';
    }

    public function send(array $messages): array
    {
        $results = [];
        /** @var list<array{reference: string, phone: string, text: string}> $recipients */
        $recipients = [];

        foreach ($messages as $message) {
            $phone = PhoneNumber::toInternationalDigits($message->to);

            if ($phone === null) {
                $results[$message->reference] = SmsResult::refused('Not a usable phone number.');

                continue;
            }

            $recipients[] = ['reference' => $message->reference, 'phone' => $phone, 'text' => $message->text];
        }

        if ($recipients === []) {
            return $results;
        }

        $texts = array_values(array_unique(array_column($recipients, 'text')));

        $result = count($texts) === 1
            ? $this->sendCampaign($texts[0], array_map(
                fn (string $phone): array => ['phone_number' => $phone, 'intended_delivery_channel' => 'sms'],
                array_values(array_unique(array_column($recipients, 'phone'))),
            ))
            : $this->sendCampaign(self::TEXT_PLACEHOLDER, array_map(
                fn (array $recipient): array => [
                    'phone_number' => $recipient['phone'],
                    'intended_delivery_channel' => 'sms',
                    'placeholder' => [[self::TEXT_PLACEHOLDER => $recipient['text']]],
                ],
                $recipients,
            ));

        foreach ($recipients as $recipient) {
            $results[$recipient['reference']] = $result;
        }

        return $results;
    }

    public function deliveryReport(string $providerReference): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->timeout(20)
                ->get($this->url.'/api/v1/report/sms/'.rawurlencode($providerReference));
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $report = [];

        foreach ((array) $response->json('data.report') as $row) {
            $phone = PhoneNumber::toInternationalDigits((string) ($row['recipient'] ?? ''));

            if ($phone === null) {
                continue;
            }

            $status = mb_strtolower((string) ($row['status'] ?? ''));

            /*
             * Before Omnichannel PR #208 a message the gateway had not yet
             * reported on came back as "Undelivered". Those versions send no
             * gateway_status field, so without it "Undelivered" cannot be
             * trusted and is read as not known yet.
             */
            if ($status === 'undelivered' && ! array_key_exists('gateway_status', $row)) {
                $status = 'pending';
            }

            $report[$phone] = [
                'status' => in_array($status, ['delivered', 'undelivered'], true) ? $status : 'pending',
                'detail' => isset($row['gateway_status']) ? mb_strtolower((string) $row['gateway_status']) : null,
            ];
        }

        return $report;
    }

    /**
     * @param  list<array<string, mixed>>  $users
     */
    private function sendCampaign(string $text, array $users): SmsResult
    {
        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->timeout(20)
                ->post($this->url.'/api/v1/campaign/send', [[
                    'users' => $users,
                    'sender_id' => $this->senderId,
                    'message' => $text,
                    'is_scheduled' => false,
                ]]);
        } catch (ConnectionException $e) {
            return SmsResult::refused('Could not reach the SMS provider: '.$e->getMessage());
        }

        if ($response->status() === 429) {
            return SmsResult::throttled(max(1, (int) ($response->header('Retry-After') ?: 60)));
        }

        if (! $response->successful()) {
            $reason = $response->json('message') ?? $response->body();

            return SmsResult::refused('SMS provider refused ('.$response->status().'): '.mb_substr((string) $reason, 0, 300));
        }

        $campaignId = $response->json('data.campaign_id');

        return SmsResult::accepted($campaignId !== null ? (string) $campaignId : null);
    }
}
