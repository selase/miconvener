<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('store_merchandise', function (Blueprint $table): void {
            $table->string('order_mode', 30)->default('stock');
            $table->string('enquiry_phone', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('store_merchandise', function (Blueprint $table): void {
            $table->dropColumn(['order_mode', 'enquiry_phone']);
        });
    }
};
