<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('store_listing_media', function (Blueprint $table): void {
            $table->string('disk')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('checksum', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('store_listing_media', function (Blueprint $table): void {
            $table->dropColumn(['disk', 'mime', 'byte_size', 'checksum']);
        });
    }
};
