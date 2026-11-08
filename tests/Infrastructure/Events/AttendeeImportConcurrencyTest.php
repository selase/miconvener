<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Services\Events\AttendeeImport;
use App\Services\Events\RegistrationQuota;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    expect((string) config('database.connections.landlord.database'))->toContain('testing');
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Queue::fake();
    Mail::fake();
    $this->tenant = Tenant::factory()->create(['slug' => 'import-race', 'isolation_mode' => 'shared']);
    $this->actor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($this->actor->id);
    setPermissionsTeamId($this->tenant->id);
    $this->actor->assignRole('Org Admin');
    app(TenantContext::class)->setTenant($this->tenant);
});

afterEach(function (): void {
    $this->truncateDatabaseTables();
});

function importRaceBatch(Event $event, User $actor, string $email): string
{
    $service = app(AttendeeImport::class);
    $preview = $service->preview($event, $actor, UploadedFile::fake()->createWithContent('a.csv', "full_name,email\nImported,{$email}"));
    $service->confirm($preview, (string) Illuminate\Support\Str::uuid(), $preview->rows()->pluck('id')->all());

    return $preview->id;
}

/** @param list<array<string,string>> $jobs */
function runImportRace(Event $event, array $jobs, bool $quota = false): void
{
    $db = $event->getConnection();
    $config = config('database.connections.landlord');
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => $config['database'], 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false', 'PAO_DISABLE' => '1'];
    $workers = array_map(fn ($job) => (new Process([PHP_BINARY, base_path('tests/Fixtures/events/attendee-import-worker.php')], base_path(), $environment))->setTimeout(90)->setInput(json_encode($job, JSON_THROW_ON_ERROR)), $jobs);
    $db->beginTransaction();
    if ($quota) {
        app(RegistrationQuota::class)->acquire($event);
    } else {
        Event::withoutGlobalScopes()->lockForUpdate()->findOrFail($event->id);
    }
    try {
        foreach ($workers as $worker) {
            $worker->start();
        }
        $deadline = microtime(true) + 30;
        do {
            $db->select('select pg_stat_clear_snapshot()');
            $waiting = (int) $db->selectOne("select count(*) as waiting from pg_stat_activity where datname=current_database() and application_name='attendee-import-worker' and wait_event_type='Lock'")->waiting;
            usleep(20000);
        } while ($waiting < count($workers) && microtime(true) < $deadline);
        expect($waiting)->toBe(count($workers), implode(' | ', array_map(fn ($worker): string => $worker->getErrorOutput().' '.$worker->getOutput(), $workers)));
        $db->commit();
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        }
    } finally {
        if ($db->transactionLevel() > 0) {
            $db->rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }
}

test('import and public admission share the final place', function (): void {
    $event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id, 'capacity' => 1]);
    $batch = importRaceBatch($event, $this->actor, 'import@example.test');
    runImportRace($event, [['event' => $event->id, 'action' => 'import', 'import' => $batch], ['event' => $event->id, 'action' => 'public', 'email' => 'public@example.test']]);
    expect($event->registrations()->confirmed()->count())->toBe(1);
});

test('different events share the final tenant registration allowance', function (): void {
    TenantFeature::create(['tenant_id' => $this->tenant->id, 'feature_key' => 'event_registrations', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 1]]);
    $events = Event::factory()->count(2)->published()->create(['tenant_id' => $this->tenant->id, 'capacity' => 10]);
    $batch = importRaceBatch($events[0], $this->actor, 'import@example.test');
    runImportRace($events[0], [['event' => $events[0]->id, 'action' => 'import', 'import' => $batch], ['event' => $events[1]->id, 'action' => 'public', 'email' => 'public@example.test']], true);
    expect(EventRegistration::where('tenant_id', $this->tenant->id)->confirmed()->count())->toBe(1)->and((int) $this->tenant->usage()->where('feature_slug', 'event_registrations')->sum('used_count'))->toBe(1);
});

test('competing batches with one identity create only one ticket', function (): void {
    $event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id, 'capacity' => 10]);
    $first = importRaceBatch($event, $this->actor, 'same@example.test');
    $second = importRaceBatch($event, $this->actor, 'same@example.test');
    runImportRace($event, [['event' => $event->id, 'action' => 'import', 'import' => $first], ['event' => $event->id, 'action' => 'import', 'import' => $second]]);
    expect($event->registrations()->confirmed()->count())->toBe(1)->and($event->registrations()->whereNotNull('ticket_code')->count())->toBe(1);
});

test('import never takes an existing registration lock while verification or cancellation waits for its event', function (string $action): void {
    $event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id, 'capacity' => 10]);
    $existing = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $event->id, 'email' => 'existing@example.test']);
    $batch = importRaceBatch($event, $this->actor, 'import@example.test');
    $url = Illuminate\Support\Facades\URL::temporarySignedRoute('public.events.registrations.verify', now()->addHour(), ['subdomain' => $this->tenant->slug, 'event' => $event->slug, 'registration' => $existing->id]);
    runImportRace($event, [['event' => $event->id, 'action' => $action, 'registration' => $existing->id, 'actor' => (string) $this->actor->id, 'url' => $url], ['event' => $event->id, 'action' => 'import', 'import' => $batch]]);
    expect($event->registrations()->where('email', 'import@example.test')->confirmed()->count())->toBe(1);
    if ($action === 'verify') {
        expect($existing->fresh()->email_verified_at)->not->toBeNull();
    } else {
        expect($existing->fresh()->status)->toBe('cancelled');
    }
})->with(['verify', 'cancel']);
