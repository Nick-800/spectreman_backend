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
        Schema::create('spectre_restaurants', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('name_ar', 255);
            $table->string('name_en', 255);
            $table->string('logo_url')->nullable();
            $table->string('cover_url')->nullable();
            $table->boolean('is_open')->default(true);
            $table->boolean('is_accepting_orders')->default(true);
            $table->string('opens_at', 10)->default('10:00');
            $table->string('closes_at', 10)->default('23:00');
            $table->unsignedInteger('avg_prep_minutes')->default(25);
            $table->unsignedBigInteger('delivery_fee_minor')->nullable();
            $table->decimal('rating', 3, 2)->default(5.0);
            $table->unsignedInteger('review_count')->default(0);
            $table->string('cuisine_ar', 255)->default('');
            $table->string('cuisine_en', 255)->default('');
            $table->string('phone', 32)->nullable();
            $table->text('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('spectre_categories', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('name_ar', 255);
            $table->string('name_en', 255);
            $table->string('icon_url')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('spectre_products', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('restaurant_id', 64)->index();
            $table->string('category_id', 64)->index();
            $table->string('title_ar', 255);
            $table->string('title_en', 255);
            $table->string('title_normalized_ar', 255)->index();
            $table->unsignedBigInteger('price_minor');
            $table->unsignedBigInteger('old_price_minor')->nullable();
            $table->string('image_url')->nullable();
            $table->string('unit', 32)->default('piece');
            $table->decimal('rating', 3, 2)->default(5.0);
            $table->unsignedInteger('review_count')->default(0);
            $table->boolean('in_stock')->default(true)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->json('payload')->nullable(); // full details: options, relatedIds, nutrition
            $table->timestamps();
        });

        Schema::create('spectre_banners', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('title_ar', 255)->default('');
            $table->string('title_en', 255)->default('');
            $table->string('image_url');
            $table->string('target_type', 32)->default('product'); // product, category, restaurant, url
            $table->string('target_id', 64)->default('');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spectre_banners');
        Schema::dropIfExists('spectre_products');
        Schema::dropIfExists('spectre_categories');
        Schema::dropIfExists('spectre_restaurants');
    }
};
