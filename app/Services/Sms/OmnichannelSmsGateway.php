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
 * so a 202 here means "queued", not "delivered". Messages with the same text
 * travel as one campaign to save requests; personalised texts go one by one.
 */
final class OmnichannelSmsGateway implements SmsGateway
{
    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly string $senderId,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            rtrim((string) config('services.omnichannel.url'), '/'),
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
        /** @var array<string, list<array{reference: string, phone: string}>> $byText */
        $byText = [];

        foreach ($messages as $message) {
            $phone = PhoneNumber::toInternationalDigits($message->to);

            if ($phone === null) {
                $results[$message->reference] = SmsResult::refused('Not a usable phone number.');

                continue;
            }

            $byText[$message->text][] = ['reference' => $message->reference, 'phone' => $phone];
        }

        foreach ($byText as $text => $recipients) {
            $result = $this->sendCampaign((string) $text, array_column($recipients, 'phone'));

            foreach ($recipients as $recipient) {
                $results[$recipient['reference']] = $result;
            }
        }

        return $results;
    }

    /**
     * @param  list<string>  $phones
     */
    private function sendCampaign(string $text, array $phones): SmsResult
    {
        $users = array_map(
            fn (string $phone): array => ['phone_number' => $phone, 'intended_delivery_channel' => 'sms'],
            array_values(array_unique($phones)),
        );

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

        if (! $response->successful()) {
            $reason = $response->json('message') ?? $response->body();

            return SmsResult::refused('SMS provider refused ('.$response->status().'): '.mb_substr((string) $reason, 0, 300));
        }

        $campaignId = $response->json('data.campaign_id');

        return SmsResult::accepted($campaignId !== null ? (string) $campaignId : null);
    }
}
