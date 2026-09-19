<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RunCommandRequest;
use App\Jobs\Operations\RunOperationalCommand;
use App\Models\CommandRun;
use App\Services\Operations\OperationalCommands;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/**
 * Lets a global Superadmin run the operational commands that otherwise need a
 * shell, and keeps a record of each run.
 */
final class OperationsController extends Controller
{
    public function index(): View
    {
        $this->authorize('access-superadmin-dashboard');

        return view('admin.operations.index', [
            'groups' => OperationalCommands::grouped(),
            'runs' => CommandRun::query()->latest('id')->limit(25)->get(),
        ]);
    }

    public function store(RunCommandRequest $request): RedirectResponse
    {
        $key = (string) $request->validated('command');
        $definition = OperationalCommands::find($key);

        if ($definition === null) {
            return back()->with('error', 'That command is not available.');
        }

        if ($definition['guarded'] && $request->validated('confirmation') !== $definition['command']) {
            return back()->with(
                'error',
                "Type {$definition['command']} to confirm before running it."
            );
        }

        /** @var array<int, string> $options */
        $options = array_values((array) ($request->validated('options') ?? []));

        $run = CommandRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'command_key' => $key,
            'command' => $definition['command'],
            'options' => $options,
            'status' => CommandRun::STATUS_QUEUED,
            'triggered_by' => $request->user()?->id,
            'triggered_by_email' => $request->user()?->email,
        ]);

        RunOperationalCommand::dispatch($run);

        return redirect()
            ->route('admin.operations.show', $run->uuid)
            ->with('success', "{$definition['label']} is running. This page shows the result.");
    }

    public function show(CommandRun $run): View
    {
        $this->authorize('access-superadmin-dashboard');

        return view('admin.operations.show', [
            'run' => $run,
            'definition' => OperationalCommands::find($run->command_key),
        ]);
    }
}
