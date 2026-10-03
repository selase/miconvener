<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Expand store_listings with vendor/service attributes
        Schema::connection('landlord')->table('store_listings', function (Blueprint $table): void {
            $table->string('category', 64)->nullable()->after('listing_kind');
            $table->jsonb('service_scope')->nullable()->after('capacity_breakdown');
            $table->unsignedBigInteger('min_order_pesewas')->default(0)->after('security_deposit_pesewas');
            $table->integer('lead_time_days')->default(1)->after('min_order_pesewas');

            $table->index(['listing_kind', 'category', 'status'], 'idx_listings_kind_cat_status');
        });

        // 2. Expand shops with credentials, ratings, and vendor categories
        Schema::connection('landlord')->table('shops', function (Blueprint $table): void {
            $table->jsonb('vendor_categories')->nullable()->after('region');
            $table->string('business_registration_number', 100)->nullable()->after('vendor_categories');
            $table->string('tax_id', 100)->nullable()->after('business_registration_number');
            $table->jsonb('verification_documents')->nullable()->after('tax_id');
            $table->jsonb('past_clients')->nullable()->after('verification_documents');
            $table->decimal('average_rating', 3, 2)->default(0.00)->after('past_clients');
            $table->unsignedInteger('reviews_count')->default(0)->after('average_rating');
        });

        // 3. Create marketplace_quotes table
        Schema::connection('landlord')->create('marketplace_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('quote_reference', 32)->unique();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUuid('store_listing_id')->nullable()->constrained('store_listings')->nullOnDelete();
            $table->foreignUuid('planner_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->nullOnDelete();

            $table->string('planner_name');
            $table->string('planner_email');
            $table->string('planner_phone', 50)->nullable();
            $table->string('event_title')->nullable();
            $table->date('event_date')->nullable();
            $table->integer('guest_count')->nullable();
            $table->string('location_address')->nullable();
            $table->text('requirements_description');

            $table->string('status', 32)->default('pending_quote');
            $table->jsonb('items')->nullable();
            $table->unsignedBigInteger('subtotal_pesewas')->default(0);
            $table->unsignedBigInteger('delivery_fee_pesewas')->default(0);
            $table->unsignedBigInteger('tax_pesewas')->default(0);
            $table->unsignedBigInteger('total_amount_pesewas')->default(0);
            $table->unsignedBigInteger('deposit_required_pesewas')->default(0);
            $table->unsignedBigInteger('amount_paid_pesewas')->default(0);
            $table->timestamp('valid_until')->nullable();
            $table->text('vendor_notes')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('paystack_reference', 100)->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'idx_quotes_tenant_status');
            $table->index(['shop_id', 'status'], 'idx_quotes_shop_status');
            $table->index(['planner_email', 'status'], 'idx_quotes_planner_status');
        });

        // 4. Create marketplace_reviews table
        Schema::connection('landlord')->create('marketplace_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUuid('store_listing_id')->nullable()->constrained('store_listings')->nullOnDelete();
            $table->foreignUuid('marketplace_quote_id')->nullable()->constrained('marketplace_quotes')->nullOnDelete();
            $table->foreignUuid('planner_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();

            $table->string('planner_name');
            $table->string('planner_email');
            $table->smallInteger('rating'); // 1 to 5
            $table->string('title')->nullable();
            $table->text('comment');
            $table->boolean('is_verified_booking')->default(true);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['shop_id', 'is_published'], 'idx_reviews_shop_published');
            $table->index(['store_listing_id', 'is_published'], 'idx_reviews_listing_published');
            $table->index(['shop_id', 'planner_email'], 'idx_reviews_shop_planner');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('marketplace_reviews');
        Schema::connection('landlord')->dropIfExists('marketplace_quotes');

        Schema::connection('landlord')->table('shops', function (Blueprint $table): void {
            $table->dropColumn([
                'vendor_categories',
                'business_registration_number',
                'tax_id',
                'verification_documents',
                'past_clients',
                'average_rating',
                'reviews_count',
            ]);
        });

        Schema::connection('landlord')->table('store_listings', function (Blueprint $table): void {
            $table->dropIndex('idx_listings_kind_cat_status');
            $table->dropColumn([
                'category',
                'service_scope',
                'min_order_pesewas',
                'lead_time_days',
            ]);
        });
    }
};
