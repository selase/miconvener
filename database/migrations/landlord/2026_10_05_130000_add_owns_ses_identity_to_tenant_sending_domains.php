<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether MiConvener created the domain's SES identity. The SES account is
 * shared with other products, so a domain that was already registered there
 * must never be deleted from SES when an organiser's set-up is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('tenant_sending_domains', function (Blueprint $table): void {
            $table->boolean('owns_ses_identity')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('tenant_sending_domains', function (Blueprint $table): void {
            $table->dropColumn('owns_ses_identity');
        });
    }
};
