<?php

declare(strict_types=1);

use App\Mail\Events\EventRegistrationConfirmed;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\SentEmail;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

it('reconnects before submitting mail on an SMTP connection that expired while the worker was idle', function (): void {
    config(['mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.smtp.username' => null,
        'mail.mailers.smtp.password' => null, 'mail.mailers.smtp.port' => 25]);
    app('mail.manager')->purge('smtp');
    $transport = Mail::mailer('smtp')->getSymfonyTransport();
    $stream = new class extends AbstractStream
    {
        public bool $expired = false;

        public int $connections = 0;

        /** @var list<string> */
        public array $commands = [];

        /** @var list<string> */
        private array $replies = [];

        public function initialize(): void
        {
            $this->connections++;
            $this->expired = false;
            $this->replies = ["220 test ESMTP\r\n"];
        }

        public function write(string $bytes, bool $debug = true): void
        {
            if (! $debug) {
                return;
            }
            $command = mb_trim($bytes);
            $this->commands[] = $command;
            if ($this->expired && ($command === 'NOOP' || str_starts_with($command, 'MAIL FROM:'))) {
                $this->replies[] = "451 4.4.2 Timeout waiting for data from client.\r\n";

                return;
            }
            $this->replies[] = match ($command) {
                'DATA' => "354 Send data\r\n",
                'QUIT' => "221 Bye\r\n",
                default => "250 Ok\r\n",
            };
        }

        public function readLine(): string
        {
            return array_shift($this->replies) ?? '';
        }

        public function flush(): void {}

        public function isTLS(): bool
        {
            return true;
        }

        protected function getReadConnectionDescription(): string
        {
            return 'in-memory SMTP server';
        }
    };
    (new ReflectionProperty(SmtpTransport::class, 'stream'))->setValue($transport, $stream);
    $email = (new Email)->from('sender@example.test')->to('attendee@example.test')->subject('Ticket')->text('Ticket ready');
    $transport->send($email);
    $stream->expired = true;
    (new ReflectionProperty(SmtpTransport::class, 'lastMessageTime'))->setValue($transport, microtime(true) - 11);

    expect($transport->send($email))->toBeInstanceOf(SentMessage::class)
        ->and($stream->connections)->toBe(2)
        ->and($stream->commands)->toContain('NOOP');
});

/** @return array{EventRegistration, ArrayTransport} */
function queueTicketWithSmtpFailures(int $failures): array
{
    config(['queue.default' => 'database', 'queue.connections.database.connection' => 'landlord']);
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($tenant);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED, 'amount' => 0, 'charged_amount' => 0]);
    $registration->issueTicket();
    $registration->save();
    $transport = new class($failures) extends ArrayTransport
    {
        public int $attempts = 0;

        public function __construct(private readonly int $failures)
        {
            parent::__construct();
        }

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            if (++$this->attempts <= $this->failures) {
                throw new UnexpectedResponseException('451 4.4.2 Timeout waiting for data from client.', 451);
            }

            return parent::send($message, $envelope);
        }
    };
    Mail::mailer('array')->setSymfonyTransport($transport);
    Mail::mailer('array')->to($registration->email)->queue(new EventRegistrationConfirmed($registration));

    return [$registration, $transport];
}

it('retries a rejected ticket send despite a one-attempt worker without reissuing its ticket', function (): void {
    [$registration, $transport] = queueTicketWithSmtpFailures(1);
    $code = $registration->ticket_code;
    $token = $registration->qr_token;
    $worker = app('queue.worker');
    $options = new WorkerOptions(sleep: 0, maxTries: 1);

    $worker->runNextJob('database', 'default', $options);
    expect(Queue::connection('database')->size())->toBe(1)
        ->and(SentEmail::query()->where('event_id', $registration->event_id)->count())->toBe(0);
    $this->travel(9)->seconds();
    $worker->runNextJob('database', 'default', $options);
    expect($transport->attempts)->toBe(1);
    $this->travel(2)->seconds();
    $worker->runNextJob('database', 'default', $options);

    expect($transport->attempts)->toBe(2)
        ->and($transport->messages())->toHaveCount(1)
        ->and(Queue::connection('database')->size())->toBe(0)
        ->and(SentEmail::query()->where('event_id', $registration->event_id)->where('mailable', 'EventRegistrationConfirmed')->count())->toBe(1)
        ->and($registration->fresh()->ticket_code)->toBe($code)
        ->and($registration->fresh()->qr_token)->toBe($token)
        ->and(EventRegistration::query()->where('event_id', $registration->event_id)->count())->toBe(1);
});

it('stops retrying a persistently rejected ticket after three bounded attempts', function (): void {
    [, $transport] = queueTicketWithSmtpFailures(10);
    $failures = [];
    Events::listen(JobFailed::class, function (JobFailed $event) use (&$failures): void {
        $failures[] = $event->exception::class;
    });
    $worker = app('queue.worker');
    $options = new WorkerOptions(sleep: 0, maxTries: 1);
    $worker->runNextJob('database', 'default', $options);
    $this->travel(11)->seconds();
    $worker->runNextJob('database', 'default', $options);
    $this->travel(59)->seconds();
    $worker->runNextJob('database', 'default', $options);
    expect($transport->attempts)->toBe(2);
    $this->travel(2)->seconds();
    $worker->runNextJob('database', 'default', $options);

    expect($transport->attempts)->toBe(3)
        ->and($transport->messages())->toHaveCount(0)
        ->and(Queue::connection('database')->size())->toBe(0)
        ->and($failures)->toBe([UnexpectedResponseException::class]);
});
