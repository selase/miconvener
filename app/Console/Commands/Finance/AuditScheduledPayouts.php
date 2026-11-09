<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Models\User;
use App\Services\Finance\PayoutFinancialAudit;
use Illuminate\Console\Command;

final class AuditScheduledPayouts extends Command
{
    protected $signature = 'payouts:audit-schedules {--record : Certify matched evidence after authorised finance review} {--actor= : Platform reviewer user ID} {--statement= : Provider statement reference} {--note= : Reconciliation explanation} {--external-cash=0 : Explicitly reconciled non-MiConvener cash in pesewas}';

    protected $description = 'Read-only reconciliation by default; never creates, releases or repeats a transfer';

    public function handle(PayoutFinancialAudit $audit): int
    {
        if (filter_var($this->option('external-cash'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            $this->error('External cash must be a non-negative integer in pesewas.');

            return self::FAILURE;
        }
        $report = $audit->inspect((int) $this->option('external-cash'));
        $this->line('Provider GHS cash: '.$report['provider_balance'].'; ledger cash: '.$report['book_cash'].'; separately reconciled external cash: '.$report['external_cash']);
        foreach ($report['unresolved'] as $exception) {
            $this->warn($exception);
        }
        $this->line($report['matched'] ? 'Matched. No money moved.' : 'Needs review or exceeds the bounded scan. No checkpoint certified.');
        if (! $this->option('record')) {
            $this->line('Read-only: controls unchanged. Use --record with an authorised reviewer to certify a match or block discovered exceptions.');
        }
        if ($this->option('record')) {
            $actor = User::query()->findOrFail($this->option('actor'));
            if (! $report['matched']) {
                $audit->recordException($report, $actor);
                $this->error('Exceptions recorded; new submissions blocked pending matched finance reconciliation.');

                return self::FAILURE;
            }
            $audit->record($report, $actor, (string) $this->option('statement'), (string) $this->option('note'));
            $this->info('Matched evidence certified by the platform reviewer. No transfer submitted.');
        }

        return $report['matched'] ? self::SUCCESS : self::FAILURE;
    }
}
