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
        Schema::create('spectre_cities', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('name_ar', 255);
            $table->string('name_en', 255);
            $table->timestamps();
        });

        Schema::create('spectre_areas', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('city_id', 64)->index();
            $table->string('name_ar', 255);
            $table->string('name_en', 255);
            $table->timestamps();
        });

        Schema::create('spectre_zones', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('city_id', 64)->index();
            $table->string('area_id', 64)->index();
            $table->string('warehouse_id', 64)->index();
            $table->string('name_ar', 255)->default('');
            $table->string('name_en', 255)->default('');
            $table->unsignedBigInteger('delivery_fee_minor')->default(5000);
            $table->unsignedBigInteger('min_order_minor')->default(20000);
            $table->unsignedInteger('eta_minutes')->default(35);
            $table->boolean('is_active')->default(true);
            $table->json('polygon_geojson')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spectre_zones');
        Schema::dropIfExists('spectre_areas');
        Schema::dropIfExists('spectre_cities');
    }
};
