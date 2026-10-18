<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->boolean('members_only')->default(false);
            $table->bigInteger('member_ticket_price')->nullable();
        });
        Schema::connection('landlord')->table('event_ticket_types', function (Blueprint $table): void {
            $table->bigInteger('member_price')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_ticket_types', fn (Blueprint $table) => $table->dropColumn('member_price'));
        Schema::connection('landlord')->table('events', fn (Blueprint $table) => $table->dropColumn(['members_only', 'member_ticket_price']));
    }
};
