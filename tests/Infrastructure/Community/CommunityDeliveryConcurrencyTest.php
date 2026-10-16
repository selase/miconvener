<?php

declare(strict_types=1);

use App\Services\Billing\EmailAllowance;
use App\Services\Community\CommunityMessages;
use App\Services\Sms\SmsAllowance;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/Fixtures/community/message-test-setup.php';
uses(DatabaseTruncation::class);
afterEach(function (): void {
    $this->truncateDatabaseTables();
});

test('two workers claim and meter the same delivery only once', function (string $channel) {
    setupCommunityMessagesTest($this);
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, array_replace($this->input, ['channels' => [$channel]]));
    app(CommunityMessages::class)->dispatchDue($message->id);
    $delivery = $message->deliveries()->firstOrFail();
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false'];
    $workers = array_map(fn () => new Process([PHP_BINARY, base_path('tests/Fixtures/community/delivery-worker.php'), $message->id, $delivery->id, $channel], base_path(), $environment), [1, 2]);
    $connection = DB::connection('landlord');
    $connection->beginTransaction();
    $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['credits:'.($channel === 'sms' ? 'sms_credits' : 'email_credits').':'.$this->tenant->id]);
    try {
        foreach ($workers as $worker) {
            $worker->start();
        }
        $deadline = microtime(true) + 15;
        do {
            $ready = count(array_filter($workers, fn ($worker) => str_contains($worker->getOutput(), 'READY')));
            usleep(20000);
        } while ($ready < 2 && microtime(true) < $deadline);
        expect($ready)->toBe(2, implode(' | ', array_map(fn ($worker) => $worker->getOutput().$worker->getErrorOutput(), $workers)));
        $deadline = microtime(true) + 10;
        do {
            $connection->select('select pg_stat_clear_snapshot()');
            $waiting = (int) $connection->selectOne("select count(*) as waiting from pg_stat_activity where datname = current_database() and wait_event = 'advisory'")->waiting;
            usleep(20000);
        } while ($waiting < 2 && microtime(true) < $deadline);
        expect($waiting)->toBeGreaterThanOrEqual(2);
        $connection->commit();
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
        }
        expect($delivery->fresh()->status)->toBe('accepted')
            ->and(app($channel === 'sms' ? SmsAllowance::class : EmailAllowance::class)->remaining($this->tenant))->toBe(99);
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }
})->with(['email', 'sms']);
