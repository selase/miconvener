<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Health\Checks\Checks\BackupsCheck;
use Spatie\Health\Enums\Status;
use Spatie\Health\Facades\Health;

/**
 * Backups fail silently. Nothing stops working when a nightly run is missed,
 * so the only thing standing between a missed run and finding out on the day
 * you need a restore is whether anyone is told.
 *
 * This shipped with Spatie's `your@example.com` placeholder as the recipient,
 * which means every failure notification since the repository began went
 * nowhere. These tests hold the two things that fix that.
 */
test('backup failures are mailed to a real address, not the packaged placeholder', function () {
    $to = config('backup.notifications.mail.to');

    expect($to)->not->toBe('your@example.com');
    expect($to)->not->toBeEmpty();
    expect($to)->toContain('@');
});

test('every way a backup can fail still reaches someone', function (string $notification) {
    expect(config("backup.notifications.notifications.{$notification}"))->toContain('mail');
})->with([
    BackupHasFailedNotification::class,
    UnhealthyBackupWasFoundNotification::class,
    CleanupHasFailedNotification::class,
]);

test('routine success is not mailed, so a failure is not buried in daily noise', function () {
    // Positive confirmation lives on the health dashboard, which is polled,
    // rather than in mail nobody reads.
    expect(config('backup.notifications.notifications.'.BackupWasSuccessfulNotification::class))->toBe([]);
});

test('the health dashboard watches the backups, so silence cannot pass for success', function () {
    $registered = collect(Health::registeredChecks())
        ->map(fn ($check): string => $check::class);

    expect($registered)->toContain(BackupsCheck::class);
});

test('a backup is the database and nothing else', function () {
    // base_path() used to be archived nightly: a 65MB file around a 20MB
    // database, the rest being git-tracked source, a vendored theme and build
    // output. A deployed filesystem is rebuilt from the repository on every
    // deploy, so none of it was ever recoverable only from a backup.
    expect(config('backup.backup.source.files.include'))->toBe([]);
    expect(config('backup.backup.source.databases'))->toContain('landlord');
});

test('history reaches far enough back to catch a bug nobody noticed for weeks', function () {
    // The threat is a quiet data bug, not a lost disk. Dailies have to outlive
    // the time it takes someone to notice, and monthlies have to cover a
    // question asked about last year's money.
    expect(config('backup.cleanup.default_strategy.keep_daily_backups_for_days'))
        ->toBeGreaterThanOrEqual(30);
    expect(config('backup.cleanup.default_strategy.keep_monthly_backups_for_months'))
        ->toBeGreaterThanOrEqual(12);
});

test('the backup check looks at the bucket the backups are actually written to', function () {
    $check = collect(Health::registeredChecks())
        ->first(fn ($check): bool => $check instanceof BackupsCheck);

    expect($check)->not->toBeNull();

    // Run it against a disk holding a backup shaped like a real one, rather
    // than asserting how it is configured. An earlier version of this test
    // checked that the path was a "Name/*.zip" glob and passed happily while
    // the check reported zero backups in production: on a real disk it lists
    // a directory instead of globbing, so the pattern matched nothing.
    $disk = config('backup.backup.destination.disks')[0];
    Storage::fake($disk);
    Storage::disk($disk)->put(
        config('backup.backup.name').'/2026-09-20-01-30-53.zip',
        str_repeat('x', 2 * 1024 * 1024),
    );

    // The registered check bound to the real disk when the provider booted, so
    // point it at the fake. Everything else it was configured with -- above all
    // the path it looks in -- is left exactly as the provider set it.
    expect($check->onDisk($disk)->run()->status->value)->toBe(Status::ok()->value);
});
