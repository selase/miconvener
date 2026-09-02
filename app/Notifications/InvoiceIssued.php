<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Invoice;
use App\Services\Tenancy\PdfInvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class InvoiceIssued extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public readonly Invoice $invoice
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $pdfService = app(PdfInvoiceService::class);
        $pdfContent = $pdfService->output($this->invoice);
        $filename = "Invoice-{$this->invoice->number}.pdf";

        return (new MailMessage)
            ->subject("Your Invoice #{$this->invoice->number} from ".config('app.name'))
            ->greeting("Hello, {$notifiable->name}!")
            ->line("An invoice for the period {$this->invoice->period_start->format('M d')} - {$this->invoice->period_end->format('M d, Y')} has been issued for your organization.")
            ->line('Total Due: **$'.number_format((float) $this->invoice->total, 2).'**')
            ->action('View Invoice', route('billing.invoices.show', $this->invoice->id))
            ->line('You can find the invoice attached as a PDF to this email.')
            ->attachData($pdfContent, $filename, [
                'mime' => 'application/pdf',
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invoice_id' => $this->invoice->id,
            'number' => $this->invoice->number,
            'total' => $this->invoice->total,
        ];
    }
}
