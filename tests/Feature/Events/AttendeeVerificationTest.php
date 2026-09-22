<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Jobs\Events\SendAttendeeAccessCode;
use App\Mail\Events\AttendeeAccessCodeMail;
use App\Models\AttendeeAccessCode;
use App\Models\Event;
use App\Models\EventAbstract;
use App\Models\EventAbstractAuthor;
use App\Models\EventCertificate;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\AttendeeVerification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;

/**
 * An attendee proves an address with an emailed code, and the proof holds for
 * one organiser only. The session cookie spans every organiser's subdomain,
 * so these tests hold that boundary in place.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Mail::fake();
});

function attendeeAt(string $slug, string $email = 'ama@stem.org'): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    return [$tenant, $registration, $slug.'.'.mb_ltrim((string) config('session.domain'), '.')];
}

/** @return array<string, array{email: string, expires_at: int}> */
function provenFor(Tenant $tenant, string $email = 'ama@stem.org'): array
{
    return ["attendee_verified.{$tenant->id}" => [
        'email' => $email,
        'expires_at' => now()->addMinutes(AttendeeVerification::VERIFIED_MINUTES)->getTimestamp(),
    ]];
}

/** The latest code, read from the mail the attendee would receive. */
function sentCode(): string
{
    $code = null;
    Mail::assertQueued(AttendeeAccessCodeMail::class, function (AttendeeAccessCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return true;
    });

    return (string) $code;
}

test('a proof on one organiser is refused on another, in the same session', function () {
    [$acme, , $acmeHost] = attendeeAt('acme-verify');
    [, , $otherHost] = attendeeAt('other-verify');

    // One browser session spans every subdomain. Only the tenant key keeps
    // Acme's proof from opening another organiser's history. The first
    // assertion proves the session really is present for the second.
    $this->withSession(provenFor($acme));

    $this->getJson("http://{$acmeHost}/my/session", ['HTTP_HOST' => $acmeHost])->assertOk();
    $this->getJson("http://{$otherHost}/my/session", ['HTTP_HOST' => $otherHost])->assertUnauthorized();
});

test('being verified does not change what the portal page sends', function () {
    [$tenant, $registration, $host] = attendeeAt('payload-verify');
    $url = "http://{$host}/e/{$registration->event->slug}/registrations/{$registration->id}";

    $anonymous = $this->get($url, ['HTTP_HOST' => $host])->viewData('page')['props'];

    $this->withSession(provenFor($tenant));
    $verified = $this->get($url, ['HTTP_HOST' => $host])->viewData('page')['props'];

    // Verified data is fetched by panels from guarded endpoints, never shipped
    // in a page, so a forwarded link can never carry it.
    expect(array_keys($verified))->toEqual(array_keys($anonymous))
        ->and($verified['registration'])->toEqual($anonymous['registration']);
});

test('asking from a portal page sends the code to the registration, not to a typed address', function () {
    [, $registration, $host] = attendeeAt('code-owner');

    $this->postJson("http://{$host}/my/verify/send", [
        'registration' => $registration->id,
        'email' => 'intruder@elsewhere.org',
    ], ['HTTP_HOST' => $host])->assertOk();

    Mail::assertQueued(AttendeeAccessCodeMail::class, fn ($mail): bool => $mail->hasTo('ama@stem.org'));
    Mail::assertNotQueued(AttendeeAccessCodeMail::class, fn ($mail): bool => $mail->hasTo('intruder@elsewhere.org'));
});

test('asking for a code answers the same whether or not the address is known', function () {
    [, , $host] = attendeeAt('code-same');

    $known = $this->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $host]);
    $unknown = $this->postJson("http://{$host}/my/verify/send", ['email' => 'nobody@stem.org'], ['HTTP_HOST' => $host]);

    expect($unknown->status())->toBe($known->status())
        ->and($unknown->json())->toEqual($known->json());
    Mail::assertQueued(AttendeeAccessCodeMail::class, 1);
});

test('sending a code does no address-dependent work before replying', function () {
    [, , $host] = attendeeAt('code-queued');
    Queue::fake();

    // The lookup, the hash and the mail push all happen in the job, not the
    // controller -- otherwise their cost would tell a caller, by timing
    // alone, whether the address meant anything here.
    $this->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $host])->assertOk();
    $this->postJson("http://{$host}/my/verify/send", ['email' => 'nobody@stem.org'], ['HTTP_HOST' => $host])->assertOk();

    Queue::assertPushed(SendAttendeeAccessCode::class, 2);
    Mail::assertNothingQueued();
});

test('the dummy hash checked when no code exists costs what a real one does', function () {
    [$tenant] = attendeeAt('dummy-cost');

    // No code exists for this address, so confirm() falls back to the dummy
    // hash. A hard-coded literal would carry whatever cost generated it,
    // however it now drifts from the app's own hashing.bcrypt.rounds; a
    // derived hash can't drift, because it's made by the same call real
    // codes are.
    app(AttendeeVerification::class)->confirm(app('session.store'), $tenant, 'nobody@stem.org', '000000');

    $property = (new ReflectionClass(AttendeeVerification::class))->getProperty('dummyHash');
    $dummyHash = (string) $property->getValue();

    expect($dummyHash)->not->toBe('')
        ->and(password_get_info($dummyHash)['options']['cost'] ?? null)->toBe((int) config('hashing.bcrypt.rounds'));
});

test('the dummy hash survives a reset static, as PHP-FPM resets it every request', function () {
    [$tenant] = attendeeAt('dummy-survives-fpm');

    // The static is a PHP process-level memo, so an earlier test in this
    // same run may have already set it; the cache store, by contrast, is
    // fresh for this test (a new Application per test). Clear the static
    // first, so the warm-up below is forced to actually populate this
    // test's own cache rather than short-circuiting on an already-set value
    // left over from another test.
    $property = (new ReflectionClass(AttendeeVerification::class))->getProperty('dummyHash');
    $property->setValue(null, null);

    // Warm the cache the way a first request would: confirm() with no
    // usable code runs the dummy path once, which populates the cache
    // behind dummyHash() (and the static in front of it).
    app(AttendeeVerification::class)->confirm(app('session.store'), $tenant, 'nobody@stem.org', '000000');

    // Simulate a fresh PHP-FPM request: the static resets to its default,
    // the cache does not.
    $property->setValue(null, null);

    Hash::spy();

    app(AttendeeVerification::class)->confirm(app('session.store'), $tenant, 'nobody@stem.org', '000000');

    Hash::shouldNotHaveReceived('make');
});

test('a code sent on one organiser does not confirm on another', function () {
    [$acme, , $acmeHost] = attendeeAt('acme-cross', 'ama@stem.org');
    [, , $otherHost] = attendeeAt('other-cross', 'ama@stem.org');

    $this->postJson("http://{$acmeHost}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $acmeHost])->assertOk();
    $code = sentCode();

    // Same code, same address, the other organiser's tenant: the code was
    // never issued there, so it must not confirm.
    $this->postJson("http://{$otherHost}/my/verify/confirm", ['email' => 'ama@stem.org', 'code' => $code], ['HTTP_HOST' => $otherHost])
        ->assertUnprocessable();
    $this->getJson("http://{$otherHost}/my/session", ['HTTP_HOST' => $otherHost])->assertUnauthorized();

    // Positive control: the same code still works where it was actually sent.
    $this->postJson("http://{$acmeHost}/my/verify/confirm", ['email' => 'ama@stem.org', 'code' => $code], ['HTTP_HOST' => $acmeHost])
        ->assertOk();
    $this->getJson("http://{$acmeHost}/my/session", ['HTTP_HOST' => $acmeHost])->assertOk();
});

test('a correct code proves the address for this organiser, whatever its capitals', function () {
    [$tenant, , $host] = attendeeAt('code-ok');

    $this->postJson("http://{$host}/my/verify/send", ['email' => 'Ama@Stem.org'], ['HTTP_HOST' => $host])->assertOk();

    $this->postJson("http://{$host}/my/verify/confirm", ['email' => 'ama@STEM.org', 'code' => sentCode()], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJson(['email' => 'am•@stem.org'])
        ->assertSessionHas("attendee_verified.{$tenant->id}.email", 'ama@stem.org');
});

test('the code is stored hashed', function () {
    [, , $host] = attendeeAt('code-hashed');

    $this->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $host]);
    $code = sentCode();

    $stored = AttendeeAccessCode::query()->sole();
    expect($stored->code_hash)->not->toBe($code)
        ->and(Hash::check($code, $stored->code_hash))->toBeTrue();
});

test('five wrong guesses kill the code, even for the right one after', function () {
    [, , $host] = attendeeAt('code-guess');

    $this->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $host]);
    $code = sentCode();
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, AttendeeAccessCode::MAX_ATTEMPTS) as $ignored) {
        $this->postJson("http://{$host}/my/verify/confirm", ['email' => 'ama@stem.org', 'code' => $wrong], ['HTTP_HOST' => $host])
            ->assertUnprocessable();
    }

    $this->postJson("http://{$host}/my/verify/confirm", ['email' => 'ama@stem.org', 'code' => $code], ['HTTP_HOST' => $host])
        ->assertUnprocessable();
});

test('a code expires after fifteen minutes', function () {
    [, , $host] = attendeeAt('code-expire');

    $this->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $host]);
    $code = sentCode();

    $this->travel(AttendeeAccessCode::TTL_MINUTES + 1)->minutes();

    $this->postJson("http://{$host}/my/verify/confirm", ['email' => 'ama@stem.org', 'code' => $code], ['HTTP_HOST' => $host])
        ->assertUnprocessable();
});

test('a code works once', function () {
    [, , $host] = attendeeAt('code-once');

    $this->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $host]);
    $code = sentCode();

    $this->postJson("http://{$host}/my/verify/confirm", ['email' => 'ama@stem.org', 'code' => $code], ['HTTP_HOST' => $host])->assertOk();
    $this->postJson("http://{$host}/my/verify/confirm", ['email' => 'ama@stem.org', 'code' => $code], ['HTTP_HOST' => $host])->assertUnprocessable();
});

test('only the latest code works', function () {
    [, , $host] = attendeeAt('code-latest');

    $this->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $host]);
    $first = sentCode();
    $this->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $host]);
    $second = sentCode();

    $this->postJson("http://{$host}/my/verify/confirm", ['email' => 'ama@stem.org', 'code' => $first], ['HTTP_HOST' => $host])->assertUnprocessable();
    $this->postJson("http://{$host}/my/verify/confirm", ['email' => 'ama@stem.org', 'code' => $second], ['HTTP_HOST' => $host])->assertOk();
});

test('one address is sent at most five codes an hour, however many IPs ask', function () {
    [, , $host] = attendeeAt('code-cap');

    foreach (range(1, AttendeeVerification::CODES_PER_HOUR + 1) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
            ->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $host])
            ->assertOk();
    }

    Mail::assertQueued(AttendeeAccessCodeMail::class, AttendeeVerification::CODES_PER_HOUR);
});

test('someone who holds only a certificate can still be sent a code', function () {
    $tenant = Tenant::factory()->create(['slug' => 'cert-only', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $host = 'cert-only.'.mb_ltrim((string) config('session.domain'), '.');

    // A speaker's certificate can exist with no registration at all.
    EventCertificate::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => null,
        'recipient_name' => 'Dr Kofi Mensah',
        'recipient_email' => 'Kofi@Stem.org',
        'role' => 'speaker',
        'issued_at' => now(),
    ]);

    $this->postJson("http://{$host}/my/verify/send", ['email' => 'kofi@stem.org'], ['HTTP_HOST' => $host])->assertOk();

    Mail::assertQueued(AttendeeAccessCodeMail::class, fn ($mail): bool => $mail->hasTo('kofi@stem.org'));
});

test('an abstract co-author who never registered can still be sent a code', function () {
    $tenant = Tenant::factory()->create(['slug' => 'author-only', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $host = 'author-only.'.mb_ltrim((string) config('session.domain'), '.');

    // Public submissions carry no user; authors are known only by address.
    $abstract = EventAbstract::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'user_id' => null,
        'code' => 'ABS-0042',
        'title' => 'Outcomes in Rural Cardiology',
        'track' => 'Cardiology',
        'presentation_preference' => 'either',
        'body' => 'Background, methods, results and conclusion.',
        'status' => EventAbstract::STATUS_ACCEPTED_ORAL,
    ]);
    EventAbstractAuthor::create([
        'abstract_id' => $abstract->id,
        'first_name' => 'Efua',
        'last_name' => 'Owusu',
        'email' => 'Efua@Stem.org',
        'affiliation' => 'Korle Bu Teaching Hospital',
        'sort_order' => 1,
    ]);

    $this->postJson("http://{$host}/my/verify/send", ['email' => 'efua@stem.org'], ['HTTP_HOST' => $host])->assertOk();

    Mail::assertQueued(AttendeeAccessCodeMail::class, fn ($mail): bool => $mail->hasTo('efua@stem.org'));
});

test('the proven address comes from the session, never from the request', function () {
    [$tenant, , $host] = attendeeAt('who-asks');

    $this->withSession(provenFor($tenant));

    $this->getJson("http://{$host}/my/session?email=kofi@stem.org", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJson(['email' => 'am•@stem.org']);
});

test('a proof lapses after two hours', function () {
    [$tenant, , $host] = attendeeAt('lapse');

    $this->withSession(provenFor($tenant));
    $this->travel(AttendeeVerification::VERIFIED_MINUTES + 1)->minutes();

    $this->getJson("http://{$host}/my/session", ['HTTP_HOST' => $host])->assertUnauthorized();
});

test('signing out forgets the proof', function () {
    [$tenant, , $host] = attendeeAt('forget');

    $this->withSession(provenFor($tenant));
    $this->postJson("http://{$host}/my/verify/forget", [], ['HTTP_HOST' => $host])->assertOk();

    $this->getJson("http://{$host}/my/session", ['HTTP_HOST' => $host])->assertUnauthorized();
});

test('the /my page asks for an address until one is proven', function () {
    [$tenant, , $host] = attendeeAt('my-page');

    $this->get("http://{$host}/my", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Events/AttendeePortal/MyPortal')
            ->where('organiser.name', $tenant->name)
            ->where('verifiedEmail', null));

    $this->withSession(provenFor($tenant));

    $this->get("http://{$host}/my", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page->where('verifiedEmail', 'am•@stem.org'));
});

test('proving an address rotates the session id', function () {
    [$tenant] = attendeeAt('rotate');

    AttendeeAccessCode::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'ama@stem.org',
        'code_hash' => Hash::make('482913'),
        'expires_at' => now()->addMinutes(AttendeeAccessCode::TTL_MINUTES),
    ]);

    $session = app('session.store');
    $session->start();
    $before = $session->getId();

    expect(app(AttendeeVerification::class)->confirm($session, $tenant, 'ama@stem.org', '482913'))->toBeTrue()
        ->and($session->getId())->not->toBe($before);
});
