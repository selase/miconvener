<?php

declare(strict_types=1);

use App\Mail\Auth\SetUpRecoveryCodesMail;
use App\Mail\Billing\BillingDailySummaryMail;
use App\Mail\Billing\PaymentFailedMail;
use App\Mail\Billing\PaymentReceiptMail;
use App\Mail\Billing\RenewalReminderMail;
use App\Mail\Billing\SubscriptionEndedMail;
use App\Mail\Billing\SubscriptionEndingMail;
use App\Mail\Events\AbstractDecisionNotificationMail;
use App\Mail\Events\AutomatedNotificationMail;
use App\Mail\Events\EventBlastMail;
use App\Mail\Events\EventRegistrationConfirmed;
use App\Mail\Events\EventRegistrationNeedsApproval;
use App\Mail\Events\EventRegistrationPaymentInvite;
use App\Mail\Events\EventRegistrationPendingApproval;
use App\Mail\Events\EventRegistrationRejected;
use App\Mail\Events\EventRegistrationVerifyEmail;
use App\Mail\Events\EventRegistrationWaitlisted;
use App\Mail\Events\EventTicketLink;
use App\Mail\Events\EventTicketTransferCode;
use App\Mail\Events\EventTicketTransferred;
use App\Mail\NewEnterpriseLead;
use App\Mail\Users\ResendAccountPassword;
use App\Mail\Users\SendAccountDetails;
use App\Mail\WelcomeMail;
use App\Models\Event;
use App\Models\EventAbstract;
use App\Models\EventBlast;
use App\Models\EventRegistration;
use App\Models\EventRegistrationTransfer;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    refreshTenantDatabases();
});

/**
 * Renders every mailable in the application.
 *
 * Mail::fake() records a mailable without ever building its view, so the rest
 * of the suite can be green while a template cannot render at all. That is
 * exactly how AutomatedNotificationMail shipped passing `view:` to a markdown
 * template: it fired on schedule, threw "No hint path defined for [mail]", and
 * every reminder was logged as failed instead of delivered.
 *
 * Every failure is collected rather than thrown at the first one, so one run
 * names every broken mailable instead of only the earliest.
 */
test('every mailable renders', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'render-check']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'name' => 'National Science Congress',
        'slug' => 'render-check-congress',
    ]);

    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'ama@stem.org',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $blast = EventBlast::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
    ]);

    $lead = Lead::factory()->create();

    // Every real send happens with a tenant resolved, so the templates are
    // rendered the same way here. Whether a queued job actually restores this
    // default is a separate question, covered in TenantAwareQueueUrlTest.
    URL::defaults(['subdomain' => $tenant->slug]);

    $abstract = EventAbstract::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'user_id' => $user->id,
        'code' => 'ABS-0001',
        'title' => 'A Structured Abstract',
        'track' => 'Cardiology',
        'presentation_preference' => 'either',
        'body' => 'Background, methods, results and conclusion.',
        'status' => EventAbstract::STATUS_ACCEPTED_ORAL,
        'decision_notes' => 'Accepted by the committee.',
    ]);

    $transfer = EventRegistrationTransfer::create([
        'tenant_id' => $tenant->id,
        'registration_id' => $registration->id,
        'to_full_name' => 'Kofi Mensah',
        'to_email' => 'kofi@stem.org',
        'code_hash' => hash('sha256', '123456'),
        'expires_at' => now()->addHour(),
    ]);

    // Shape taken from BillingDailySummary's documented return type, so this
    // fails if the producer and the template drift apart.
    $summary = [
        'renewal_run' => ['at' => now()->format('j F Y H:i'), 'stale' => false, 'actions' => ['Renewed 3 plans']],
        'past_due' => [['tenant' => 'Acme', 'plan' => 'Starter', 'grace_ends' => '19 October 2026', 'method' => 'card']],
        'renewing_soon' => [['tenant' => 'Acme', 'plan' => 'Starter', 'on' => '12 October 2026', 'amount' => 'GHS 250.00', 'method' => 'card']],
        'payments' => ['count' => 2, 'totals' => ['GHS 500.00']],
        'failed_jobs' => 0,
        'webhook_failures' => ['processing' => 0, 'signature' => 0],
    ];

    $mailables = [
        'SetUpRecoveryCodesMail' => fn () => new SetUpRecoveryCodesMail('Ama', 'Acme Events', 'https://acme.test/account'),
        'BillingDailySummaryMail' => fn () => new BillingDailySummaryMail($summary),
        'PaymentFailedMail' => fn () => new PaymentFailedMail($tenant, 'GHS 250.00', '12 October 2026', 'https://acme.test/billing', '19 October 2026'),
        'PaymentReceiptMail' => fn () => new PaymentReceiptMail($tenant, 'Starter plan', 'GHS 250.00', 'ref_123', '12 October 2026', 'Visa ending 4242', 'Starter', 'https://acme.test/billing'),
        'RenewalReminderMail' => fn () => new RenewalReminderMail($tenant, 'Starter', 'GHS 250.00', '12 October 2026', false, '19 October 2026', 'https://acme.test/pay'),
        'SubscriptionEndedMail' => fn () => new SubscriptionEndedMail($tenant, 'Starter', 'https://acme.test/billing'),
        'SubscriptionEndingMail' => fn () => new SubscriptionEndingMail($tenant, 'Starter', '12 October 2026', 'https://acme.test/billing'),
        'AbstractDecisionNotificationMail' => fn () => new AbstractDecisionNotificationMail($event, $abstract, 'Congratulations.'),
        'AutomatedNotificationMail' => fn () => new AutomatedNotificationMail($event, 'Ama Serwaa', 'Two days to go', 'Congress begins in 2 days.', 'https://acme.test/pass', 'View Digital Pass'),
        'EventBlastMail' => fn () => new EventBlastMail($blast, 'Ama Serwaa', $registration->id),
        'EventRegistrationConfirmed' => fn () => new EventRegistrationConfirmed($registration),
        'EventRegistrationNeedsApproval' => fn () => new EventRegistrationNeedsApproval($registration),
        'EventRegistrationPaymentInvite' => fn () => new EventRegistrationPaymentInvite($registration, 'Your seat is held until payment.'),
        'EventRegistrationPendingApproval' => fn () => new EventRegistrationPendingApproval($registration),
        'EventRegistrationRejected' => fn () => new EventRegistrationRejected($registration),
        'EventRegistrationVerifyEmail' => fn () => new EventRegistrationVerifyEmail($registration),
        'EventRegistrationWaitlisted' => fn () => new EventRegistrationWaitlisted($registration),
        'EventTicketLink' => fn () => new EventTicketLink($registration),
        'EventTicketTransferCode' => fn () => new EventTicketTransferCode($transfer, '123456'),
        'EventTicketTransferred' => fn () => new EventTicketTransferred($registration, 'Ama Serwaa', 'Kofi Mensah', 'kofi@stem.org'),
        'NewEnterpriseLead' => fn () => new NewEnterpriseLead($lead),
        'ResendAccountPassword' => fn () => new ResendAccountPassword(['user' => 'Ama', 'email' => 'ama@stem.org', 'password' => 'secret-temp', 'loginUrl' => 'https://acme.test/login']),
        'SendAccountDetails' => fn () => new SendAccountDetails('Ama', 'ama@stem.org', 'secret-temp', 'https://acme.test/login', 'Acme Events'),
        'WelcomeMail' => fn () => new WelcomeMail($user),
    ];

    $failures = [];

    foreach ($mailables as $name => $make) {
        try {
            $html = $make()->render();

            if (mb_trim($html) === '') {
                $failures[$name] = 'rendered empty';
            }
        } catch (Throwable $e) {
            $failures[$name] = $e->getMessage();
        }
    }

    expect($failures)->toBe([]);
});

test('the render check covers every mailable in the application', function (): void {
    $source = File::get(base_path('tests/Feature/Mail/MailableRenderTest.php'));

    $onDisk = collect(File::allFiles(app_path('Mail')))
        ->filter(fn ($file): bool => str_contains($file->getContents(), 'extends Mailable'))
        ->map(fn ($file): string => $file->getFilenameWithoutExtension())
        ->values();

    // A mailable added without a render case would inherit exactly the blind
    // spot this file exists to close, so the list is checked rather than trusted.
    $uncovered = $onDisk
        ->reject(fn (string $class): bool => str_contains($source, "'".$class."' => fn () => new "))
        ->all();

    expect($uncovered)->toBe([])
        ->and($onDisk->count())->toBeGreaterThan(20);
});
