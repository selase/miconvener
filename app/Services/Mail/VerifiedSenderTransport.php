<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final readonly class VerifiedSenderTransport implements TransportInterface
{
    public function __construct(private TransportInterface $transport, private string $from) {}

    public function __toString(): string
    {
        return 'verified-sender';
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if (! $message instanceof Email) {
            throw new TransportException('The verified sender transport requires an email message.');
        }

        $message = clone $message;
        $envelope ??= Envelope::create($message);
        $sender = new Address($this->from, ($message->getFrom()[0] ?? null)?->getName() ?? '');

        $message->from($sender);
        $message->getHeaders()->remove('Sender');
        $message->getHeaders()->remove('X-SES-MESSAGE-TAGS');

        return $this->transport->send($message, new Envelope($sender, $envelope->getRecipients()));
    }
}
