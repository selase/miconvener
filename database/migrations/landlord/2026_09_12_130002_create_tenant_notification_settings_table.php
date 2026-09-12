<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('tenant_notification_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();

            $table->unsignedInteger('email_monthly_limit')->default(2500);
            $table->unsignedInteger('email_used_this_month')->default(0);

            $table->boolean('sms_enabled')->default(false);
            $table->boolean('whatsapp_enabled')->default(false);
            $table->boolean('overage_billing_enabled')->default(false);

            $table->unsignedInteger('sms_cost_rate')->default(25); // minor units (e.g. 25 pesewas / $0.025)
            $table->unsignedInteger('whatsapp_cost_rate')->default(40); // minor units (e.g. 40 pesewas / $0.04)
            $table->unsignedInteger('email_overage_rate')->default(3); // minor units (e.g. 3 pesewas / $0.003)
            $table->unsignedInteger('anti_abuse_cooldown_minutes')->default(60);

            $table->dateTime('month_reset_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('tenant_notification_settings');
    }
};
