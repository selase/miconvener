<?php

declare(strict_types=1);

namespace App\Console\Commands\Events;

use Illuminate\Console\Command;

final class ProvisionHost extends Command
{
    protected $signature = 'events:provision-host {slug} {email} {--password=}';

    protected $description = 'One-off: provision a tenant + host user for the event registration MVP.';

    public function handle(): int
    {
        $this->error('This legacy credential-generating command is retired. Create an organisation with your account, then invite its owners through Team.');

        return self::FAILURE;
    }
}
