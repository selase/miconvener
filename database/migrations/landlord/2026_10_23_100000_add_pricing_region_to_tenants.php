<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which price an organisation pays for MiConvener: the Ghana price (GH) or the
 * USD price converted at checkout (INTL). Null until it is known. The evidence
 * for GH, and who or what recorded it, is kept beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->string('pricing_region', 8)->nullable();
            $table->jsonb('pricing_region_evidence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('tenants', fn (Blueprint $table) => $table->dropColumn(['pricing_region', 'pricing_region_evidence']));
    }
};
