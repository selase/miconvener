<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teamKey = $columnNames['team_foreign_key'];
        $modelKey = $columnNames['model_morph_key'];

        // model_has_roles
        Schema::table($tableNames['model_has_roles'], function (Blueprint $table): void {
            try {
                $table->dropPrimary();
            } catch (Throwable) {
                // Ignore if primary key doesn't exist
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            // Use raw SQL for the unique index with NULLS NOT DISTINCT (PG 15+)
            DB::statement("CREATE UNIQUE INDEX model_has_roles_team_unique ON {$tableNames['model_has_roles']} (role_id, {$modelKey}, model_type, {$teamKey}) NULLS NOT DISTINCT");
        } else {
            Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($modelKey, $teamKey): void {
                $table->unique(['role_id', $modelKey, 'model_type', $teamKey], 'model_has_roles_team_unique');
            });
        }

        // model_has_permissions
        Schema::table($tableNames['model_has_permissions'], function (Blueprint $table): void {
            try {
                $table->dropPrimary();
            } catch (Throwable) {
                // Ignore if primary key doesn't exist
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX model_has_permissions_team_unique ON {$tableNames['model_has_permissions']} (permission_id, {$modelKey}, model_type, {$teamKey}) NULLS NOT DISTINCT");
        } else {
            Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($modelKey, $teamKey): void {
                $table->unique(['permission_id', $modelKey, 'model_type', $teamKey], 'model_has_permissions_team_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $modelKey = $columnNames['model_morph_key'];

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS model_has_roles_team_unique');
        } else {
            Schema::table($tableNames['model_has_roles'], function (Blueprint $table): void {
                $table->dropUnique('model_has_roles_team_unique');
            });
        }

        Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($modelKey): void {
            $table->primary(['role_id', $modelKey, 'model_type'], 'model_has_roles_role_model_type_primary');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS model_has_permissions_team_unique');
        } else {
            Schema::table($tableNames['model_has_permissions'], function (Blueprint $table): void {
                $table->dropUnique('model_has_permissions_team_unique');
            });
        }

        Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($modelKey): void {
            $table->primary(['permission_id', $modelKey, 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });
    }
};
