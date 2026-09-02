<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\DataExportRequest;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class DataExportReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly DataExportRequest $exportRequest,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenant = Tenant::withoutGlobalScopes()->find($this->exportRequest->tenant_id);
        $tenantName = $tenant?->email_sender_name ?? config('app.name');

        $mail = new MailMessage()
            ->subject('Your Data Export is Ready')
            ->greeting('Hello!')
            ->line('Your data export has been completed and is ready for download.')
            ->line('The export will be available for 7 days.')
            ->salutation("Thanks,\n{$tenantName}");

        if ($tenant?->email_sender_address && $tenant->featureEnabled('white_label')) {
            $mail->from($tenant->email_sender_address, $tenant->email_sender_name);
        }

        return $mail;
    }
}
