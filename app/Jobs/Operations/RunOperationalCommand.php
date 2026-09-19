<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Models\CommandRun;
use App\Services\Operations\OperationalCommands;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Runs one allowlisted command and records what it printed.
 *
 * Queued rather than run in the request: these reach payment providers and
 * mail servers, and a renewal run over many tenants outlives a web request.
 */
final class RunOperationalCommand implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public readonly CommandRun $run) {}

    public function handle(): void
    {
        // Re-checked here, not taken on trust from the row: the definition is
        // the only place an Artisan string may come from.
        $definition = OperationalCommands::find($this->run->command_key);

        if ($definition === null) {
            $this->run->update([
                'status' => CommandRun::STATUS_FAILED,
                'output' => "Unknown command: {$this->run->command_key}",
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            return;
        }

        $this->run->update([
            'status' => CommandRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $parameters = [];
        foreach (($this->run->options ?? []) as $option) {
            if (array_key_exists($option, $definition['options'])) {
                $parameters[$option] = true;
            }
        }

        $buffer = new BufferedOutput;

        try {
            $exitCode = Artisan::call($definition['command'], $parameters, $buffer);

            $this->run->update([
                'status' => $exitCode === 0 ? CommandRun::STATUS_SUCCESS : CommandRun::STATUS_FAILED,
                'exit_code' => $exitCode,
                'output' => mb_substr(mb_trim($buffer->fetch()), 0, 60000),
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->run->update([
                'status' => CommandRun::STATUS_FAILED,
                'exit_code' => 1,
                'output' => mb_substr(mb_trim($buffer->fetch())."\n".$e->getMessage(), 0, 60000),
                'finished_at' => now(),
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        $this->run->update([
            'status' => CommandRun::STATUS_FAILED,
            'output' => $e->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
