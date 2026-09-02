<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Tenant;
use App\Models\UsageLimit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class UsageLimitReached extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly UsageLimit $limit,
        public readonly float $percentUsed,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $metricName = str_replace('.', ' ', $this->limit->metric->value);
        $percentFormatted = round($this->percentUsed, 1);

        return (new MailMessage)
            ->subject("Usage Alert: {$metricName} at {$percentFormatted}%")
            ->greeting("Hello, {$notifiable->first_name}!")
            ->line("Your organization **{$this->tenant->name}** has reached **{$percentFormatted}%** of its {$metricName} limit.")
            ->line("Limit: {$this->limit->limit_value} | Period: {$this->limit->period}")
            ->when($this->limit->block_on_limit, fn(MailMessage $message) => $message->line('**Requests exceeding this limit will be blocked.** Please upgrade your plan or contact support.'))
            ->action('View Usage', config('app.url').'/settings/usage');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tenant_id' => $this->tenant->id,
            'metric' => $this->limit->metric->value,
            'percent_used' => $this->percentUsed,
        ];
    }
}
