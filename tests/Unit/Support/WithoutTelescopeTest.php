<?php

declare(strict_types=1);

use App\Support\WithoutTelescope;
use Laravel\Telescope\Telescope;

it('runs a sensitive operation once and preserves its result with or without Telescope', function (): void {
    $calls = 0;
    $value = new stdClass;
    $result = WithoutTelescope::run(function () use (&$calls, $value): stdClass {
        $calls++;
        if (class_exists(Telescope::class)) {
            expect(Telescope::isRecording())->toBeFalse();
        }

        return $value;
    });

    expect($result)->toBe($value)->and($calls)->toBe(1);
});

it('propagates the original operation exception without retrying', function (): void {
    $calls = 0;
    $failure = new RuntimeException('Operation failed');
    try {
        WithoutTelescope::run(function () use (&$calls, $failure): never {
            $calls++;
            throw $failure;
        });
        test()->fail('Expected the operation exception.');
    } catch (RuntimeException $caught) {
        expect($caught)->toBe($failure);
    }
    expect($calls)->toBe(1);
});

it('restores active recording after successful and failed operations', function (): void {
    if (! class_exists(Telescope::class)) {
        test()->markTestSkipped('Telescope is intentionally absent in the production-dependency regression run.');
    }
    $wasRecording = Telescope::isRecording();
    try {
        Telescope::startRecording(false);
        expect(Telescope::isRecording())->toBeTrue();
        expect(WithoutTelescope::run(fn (): bool => Telescope::isRecording()))->toBeFalse();
        expect(Telescope::isRecording())->toBeTrue();
        expect(fn () => WithoutTelescope::run(function (): never {
            expect(Telescope::isRecording())->toBeFalse();
            throw new RuntimeException('Failure');
        }))->toThrow(RuntimeException::class);
        expect(Telescope::isRecording())->toBeTrue();
    } finally {
        if ($wasRecording) {
            Telescope::startRecording(false);
        } else {
            Telescope::stopRecording();
        }
    }
});
