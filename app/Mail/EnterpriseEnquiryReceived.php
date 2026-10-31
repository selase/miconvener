<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the person who filled in the Enterprise form, so they know it
 * arrived. Our sending address takes no mail, so a reply goes to the people
 * who receive enquiries.
 */
final class EnterpriseEnquiryReceived extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string>  $recipientEmails  who receive enquiries; replies go to them
     */
    public function __construct(public Lead $lead, public array $recipientEmails) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: array_map(fn (string $email): Address => new Address($email), $this->recipientEmails),
            subject: 'We have your enquiry - '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.leads.enquiry-received');
    }
}
