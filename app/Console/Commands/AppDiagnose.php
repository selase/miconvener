<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

final class AppDiagnose extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:diagnose';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose the current environment configuration';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Diagnostic Information');
        $this->info('----------------------');

        $this->line('queue.default: '.Config::get('queue.default'));
        $this->line('cache.default: '.Config::get('cache.default'));
        $this->line('session.driver: '.Config::get('session.driver'));
        $this->line('db.default: '.Config::get('database.default'));

        $this->info('----------------------');
        $this->info('PHP Extensions');

        $extensions = ['pdo_mysql', 'pdo_pgsql'];
        foreach ($extensions as $ext) {
            $status = extension_loaded($ext) ? 'Installed' : 'Not Installed';
            $this->line("$ext: $status");
        }

        return Command::SUCCESS;
    }
}
