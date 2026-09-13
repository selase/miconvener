<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * These columns hold a users.id, which is a bigint, but were declared uuid.
 * Postgres rejects the insert outright — every breakout-session check-in by
 * staff failed — while SQLite, which the tests run on, accepts any value.
 *
 * The columns are dropped and re-added rather than altered: Postgres has no
 * cast from uuid to bigint, and no row could have held a real value, since
 * every insert of one was refused. Production had zero rows in all four
 * tables when this was written.
 */
return new class extends Migration
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $columns = [
        'event_session_attendances' => ['checked_in_by', 'checked_out_by'],
        'ledger_transactions' => ['created_by'],
        // Required in practice, nullable in the schema: SQLite cannot add a
        // NOT NULL column without a default, and the tests run on SQLite.
        'data_export_requests' => ['requested_by'],
    ];

    public function up(): void
    {
        foreach ($this->columns as $tableName => $columns) {
            Schema::connection('landlord')->table($tableName, function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });

            Schema::connection('landlord')->table($tableName, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column) {
                    $table->foreignId($column)->nullable()->constrained('users')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->columns as $tableName => $columns) {
            Schema::connection('landlord')->table($tableName, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column) {
                    $table->dropConstrainedForeignId($column);
                }
            });

            Schema::connection('landlord')->table($tableName, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column) {
                    $table->uuid($column)->nullable();
                }
            });
        }
    }
};
