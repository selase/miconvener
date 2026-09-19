<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\Auth\SetUpRecoveryCodesMail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Asks anyone who enrolled in two-factor authentication before recovery codes
 * existed to generate a set.
 *
 * The mail carries no codes. They are a second factor, and email is the same
 * channel that resets passwords -- sending them there would leave one inbox
 * holding both halves of the login.
 */
final class PromptForRecoveryCodes extends Command
{
    protected $signature = 'auth:prompt-recovery-codes
                            {--dry-run : List who would be emailed without sending anything}';

    protected $description = 'Email users who have two-factor authentication enabled but no recovery codes.';

    public function handle(): int
    {
        $users = User::query()
            ->whereNotNull('two_factor_confirmed_at')
            ->whereNull('two_factor_recovery_codes')
            ->get();

        if ($users->isEmpty()) {
            $this->info('Every user with two-factor authentication already has recovery codes.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;

        foreach ($users as $user) {
            $tenant = $user->tenant_id === null ? null : Tenant::query()->find($user->tenant_id);

            if ($tenant === null) {
                $this->warn("Skipped {$user->email}: no organization to link them back to.");

                continue;
            }

            $this->line(($dryRun ? 'Would email' : 'Emailing')." {$user->email} ({$tenant->slug})");

            if ($dryRun) {
                continue;
            }

            Mail::to($user->email)->queue(new SetUpRecoveryCodesMail(
                $user->first_name ?? $user->displayName(),
                $tenant->name,
                $tenant->url('/account'),
            ));

            $sent++;
        }

        $this->info($dryRun ? 'Dry run: nothing sent.' : "Queued {$sent} email(s).");

        return self::SUCCESS;
    }
}
