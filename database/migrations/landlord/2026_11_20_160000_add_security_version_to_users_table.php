<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('security_version')->default(0);
        });
    }

    public function down(): void
    {
        if (Schema::connection('landlord')->getConnection()->table('users')->where('security_version', '>', 0)->exists()) {
            throw new RuntimeException('Account security revocation has been used. Roll forward instead of restoring revoked credentials.');
        }

        Schema::connection('landlord')->table('users', function (Blueprint $table): void {
            $table->dropColumn('security_version');
        });
    }
};
