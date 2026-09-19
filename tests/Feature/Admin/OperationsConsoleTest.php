<?php

declare(strict_types=1);

use App\Jobs\Operations\RunOperationalCommand;
use App\Models\CommandRun;
use App\Models\User;
use App\Services\Operations\OperationalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->superadmin = User::factory()->create(['email' => 'ops@system.com']);
    $role = Spatie\Permission\Models\Role::create([
        'name' => 'Superadmin',
        'guard_name' => 'web',
        'uuid' => Str::uuid(),
    ]);
    $this->superadmin->assignRole($role);
    $this->superadmin->givePermissionTo(Spatie\Permission\Models\Permission::create([
        'name' => 'access-superadmin-dashboard',
        'uuid' => Str::uuid(),
        'category' => 'system',
    ]));
});

test('a superadmin sees the operations page with the allowlisted commands', function () {
    $response = $this->actingAs($this->superadmin)->get(route('admin.operations.index'));

    $response->assertOk();
    $response->assertSee('Run health checks');
    $response->assertSee('Ask users to set up recovery codes');
    // A guarded command is labelled as such rather than being one click away.
    $response->assertSee('Process plan renewals');
});

test('a regular user cannot see or run operations', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.operations.index'))->assertForbidden();
    $this->actingAs($user)
        ->post(route('admin.operations.store'), ['command' => 'health-check'])
        ->assertForbidden();

    Queue::assertNothingPushed();
    expect(CommandRun::query()->count())->toBe(0);
});

test('running an unguarded command records the run and queues it', function () {
    Bus::fake();

    $this->actingAs($this->superadmin)
        ->post(route('admin.operations.store'), ['command' => 'health-check'])
        ->assertRedirect();

    $run = CommandRun::query()->sole();
    expect($run->command)->toBe('health:check');
    expect($run->status)->toBe(CommandRun::STATUS_QUEUED);
    expect($run->triggered_by_email)->toBe('ops@system.com');

    Bus::assertDispatched(RunOperationalCommand::class);
});

test('a command outside the allowlist is rejected, whatever it is', function () {
    Bus::fake();

    // The registry is the whole security boundary: an Artisan string submitted
    // here must never become a command that runs.
    foreach (['migrate:fresh', 'app:setup-database', 'health:check', 'tinker'] as $attempt) {
        $this->actingAs($this->superadmin)
            ->post(route('admin.operations.store'), ['command' => $attempt])
            ->assertSessionHasErrors('command');
    }

    Bus::assertNothingDispatched();
    expect(CommandRun::query()->count())->toBe(0);
});

test('a guarded command does nothing without the typed confirmation', function () {
    Bus::fake();

    $this->actingAs($this->superadmin)
        ->post(route('admin.operations.store'), ['command' => 'process-renewals'])
        ->assertSessionHas('error');

    expect(CommandRun::query()->count())->toBe(0);
    Bus::assertNothingDispatched();

    // With the confirmation, it runs.
    $this->actingAs($this->superadmin)
        ->post(route('admin.operations.store'), [
            'command' => 'process-renewals',
            'confirmation' => 'billing:process-renewals',
        ])
        ->assertRedirect();

    expect(CommandRun::query()->sole()->command)->toBe('billing:process-renewals');
    Bus::assertDispatched(RunOperationalCommand::class);
});

test('an option not declared for the command is refused', function () {
    Bus::fake();

    $this->actingAs($this->superadmin)
        ->post(route('admin.operations.store'), [
            'command' => 'health-check',
            'options' => ['--dry-run'],
        ])
        ->assertSessionHasErrors('options.0');

    Bus::assertNothingDispatched();
});

test('the job runs the command and captures its output', function () {
    $run = CommandRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'command_key' => 'prompt-recovery-codes',
        'command' => 'auth:prompt-recovery-codes',
        'options' => ['--dry-run'],
        'status' => CommandRun::STATUS_QUEUED,
    ]);

    (new RunOperationalCommand($run))->handle();

    $run->refresh();
    expect($run->status)->toBe(CommandRun::STATUS_SUCCESS);
    expect($run->exit_code)->toBe(0);
    expect($run->output)->toContain('recovery codes');
    expect($run->finished_at)->not->toBeNull();
});

test('the job refuses a command key that is not in the registry', function () {
    // Defence in depth: even a row written around the controller cannot turn
    // into an arbitrary Artisan call.
    $run = CommandRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'command_key' => 'migrate-fresh',
        'command' => 'migrate:fresh',
        'status' => CommandRun::STATUS_QUEUED,
    ]);

    (new RunOperationalCommand($run))->handle();

    $run->refresh();
    expect($run->status)->toBe(CommandRun::STATUS_FAILED);
    expect($run->output)->toContain('Unknown command');
});

test('every registered command actually exists in artisan', function () {
    // A typo here would only surface when someone pressed the button.
    $registered = array_keys(app(Illuminate\Contracts\Console\Kernel::class)->all());

    foreach (OperationalCommands::all() as $key => $definition) {
        expect($registered)
            ->toContain($definition['command']);
    }
});
