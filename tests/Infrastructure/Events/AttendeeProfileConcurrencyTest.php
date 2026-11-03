<?php

declare(strict_types=1);

use App\Models\EventAttendeeProfileImage;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Events\Profiles\ProfileEligibility;
use App\Services\Events\Profiles\ProfileImages;
use App\Services\Events\Profiles\ProfilePublication;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/PublicAttendeeProfileScenario.php';
uses(DatabaseTruncation::class);
afterEach(function (): void { $this->truncateDatabaseTables(); });

/** @return list<string> */
function attendeeProfileRace(array $context, array $actions): array
{
    expect(config('database.connections.landlord.database'))->toBe('miconvener_s2b_codex_testing');
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => config('database.connections.landlord.database'), 'DB_HOST' => config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false', 'PAO_DISABLE' => '1'];
    $start = microtime(true) + 2;
    $workers = array_map(fn (string $action): Process => (new Process([PHP_BINARY, base_path('tests/Fixtures/events/attendee-profile-worker.php')], base_path(), $environment))->setTimeout(60)->setInput(json_encode($context + ['action' => $action, 'start_at' => $start], JSON_THROW_ON_ERROR)), $actions);
    foreach ($workers as $worker) { $worker->start(); }
    $results = [];
    foreach ($workers as $worker) {
        $worker->wait();
        $result = json_decode($worker->getOutput(), true);
        expect($result)->toBeArray($worker->getErrorOutput())->and($result)->not->toHaveKey('error');
        $results[] = $result['result'];
    }
    return $results;
}

it('does not resurrect a profile when publication replay races withdrawal or ticket transfer', function (string $other): void {
    Storage::fake('attendee-profiles-local');
    [, , $registration, $session] = attendeeProfileScenario();
    $input = attendeeProfileInput();
    $profile = app(ProfilePublication::class)->publish($registration, $session, $input);
    attendeeProfileRace(['registration' => $registration->id, 'publication' => $input, 'session_id' => $session->getId(), 'proof' => $session->get(PlatformAttendeeVerification::SESSION_KEY), 'root' => Storage::disk('attendee-profiles-local')->path('')], ['publish', $other]);
    expect(app(ProfileEligibility::class)->visible($profile))->toBeFalse();
})->with(['withdraw', 'transfer']);

it('serialises concurrent photo reservations under the remaining quota', function (): void {
    Storage::fake('attendee-profiles-local');
    [$tenant, , $registration, $session] = attendeeProfileScenario();
    $image = app(ProfileImages::class)->upload($registration, $session, UploadedFile::fake()->image('photo.png', 60, 60));
    $tenant->forceFill(['storage_limit_snapshot' => $image->byte_size * 2])->save();
    $results = attendeeProfileRace(['registration' => $registration->id, 'session_id' => $session->getId(), 'proof' => $session->get(PlatformAttendeeVerification::SESSION_KEY), 'root' => Storage::disk('attendee-profiles-local')->path('')], ['upload', 'upload']);
    expect(count(array_filter($results, fn (string $result): bool => $result === 'done')))->toBe(1)
        ->and(count(array_filter($results, fn (string $result): bool => $result === 'refused')))->toBe(1);
    expect((int) EventAttendeeProfileImage::withoutGlobalScopes()->sum('byte_size'))->toBeLessThanOrEqual($image->byte_size * 2);
});
