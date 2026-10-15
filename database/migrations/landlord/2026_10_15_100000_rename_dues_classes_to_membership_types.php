<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dues classes become membership types, and a type may carry no dues at all
 * (churches, free alumni groups). Amount and period are set together or not
 * at all. Postgres keeps every row, foreign key and index through the rename.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('landlord');

        $schema->rename('dues_classes', 'membership_types');
        $schema->table('membership_types', function (Blueprint $table): void {
            $table->unsignedBigInteger('amount')->nullable()->change();
            $table->string('period', 16)->nullable()->change();
        });
        $schema->table('members', fn (Blueprint $table) => $table->renameColumn('dues_class_id', 'membership_type_id'));
        $schema->table('dues_charges', fn (Blueprint $table) => $table->renameColumn('dues_class_id', 'membership_type_id'));

        DB::connection('landlord')->statement(
            'ALTER TABLE membership_types ADD CONSTRAINT membership_types_dues_pair_check CHECK ((amount IS NULL) = (period IS NULL))'
        );
    }

    public function down(): void
    {
        $db = DB::connection('landlord');
        $schema = Schema::connection('landlord');

        if ($db->table('membership_types')->whereNull('amount')->exists()) {
            throw new RuntimeException('Some membership types have no dues; give them an amount and period before rolling back.');
        }

        $db->statement('ALTER TABLE membership_types DROP CONSTRAINT membership_types_dues_pair_check');
        $schema->table('dues_charges', fn (Blueprint $table) => $table->renameColumn('membership_type_id', 'dues_class_id'));
        $schema->table('members', fn (Blueprint $table) => $table->renameColumn('membership_type_id', 'dues_class_id'));
        $schema->table('membership_types', function (Blueprint $table): void {
            $table->unsignedBigInteger('amount')->nullable(false)->change();
            $table->string('period', 16)->nullable(false)->change();
        });
        $schema->rename('membership_types', 'dues_classes');
    }
};
