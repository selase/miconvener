<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->decimal('yearly_price', 10, 2)->nullable()->after('price');
            $table->boolean('is_free')->default(false)->after('is_active');
            $table->integer('sort_order')->default(0)->after('is_free');
            $table->string('paystack_plan_code')->nullable()->after('sort_order');
            $table->string('paystack_yearly_plan_code')->nullable()->after('paystack_plan_code');
            $table->string('stripe_price_id')->nullable()->after('paystack_yearly_plan_code');
            $table->string('stripe_yearly_price_id')->nullable()->after('stripe_price_id');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->foreignId('pending_package_id')->nullable()->after('current_period_end')
                ->constrained('packages')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['pending_package_id']);
            $table->dropColumn('pending_package_id');
        });

        Schema::table('packages', function (Blueprint $table): void {
            $table->dropColumn([
                'yearly_price',
                'is_free',
                'sort_order',
                'paystack_plan_code',
                'paystack_yearly_plan_code',
                'stripe_price_id',
                'stripe_yearly_price_id',
            ]);
        });
    }
};
