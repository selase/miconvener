<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\AttendeeAccessCodeMail;
use App\Models\AttendeeAccessCode;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    refreshTenantDatabases();
});

test('the code survives the queue', function () {
    $tenant = Tenant::factory()->create(['slug' => 'code-queue', 'name' => 'Acme Events']);

    // The same round trip a worker makes. See the transfer-code test for why
    // the code is a constructor argument and not a property of a model.
    $rebuilt = unserialize(serialize(new AttendeeAccessCodeMail($tenant, '482913')));

    expect($rebuilt->render())->toContain('482913')->toContain('Acme Events')
        ->and($rebuilt->envelope()->subject)->toContain('Acme Events');
});

test('a code is stored only as its hash', function () {
    $code = AttendeeAccessCode::factory()->create(['code_hash' => Hash::make('482913')]);

    expect($code->code_hash)->not->toBe('482913')
        ->and($code->matches('482913'))->toBeTrue()
        ->and($code->matches(' 482913 '))->toBeTrue()
        ->and($code->matches('000000'))->toBeFalse();
});

test('a code stops working once expired, used, or guessed at too often', function () {
    $fresh = AttendeeAccessCode::factory()->create();
    $expired = AttendeeAccessCode::factory()->create(['expires_at' => now()->subMinute()]);
    $used = AttendeeAccessCode::factory()->create(['consumed_at' => now()]);
    $guessed = AttendeeAccessCode::factory()->create(['attempts' => AttendeeAccessCode::MAX_ATTEMPTS]);

    expect($fresh->isUsable())->toBeTrue()
        ->and($expired->isUsable())->toBeFalse()
        ->and($used->isUsable())->toBeFalse()
        ->and($guessed->isUsable())->toBeFalse();
});

test('codes are six digits', function () {
    foreach (range(1, 50) as $ignored) {
        expect(AttendeeAccessCode::generateCode())->toMatch('/^\d{6}$/');
    }
});
