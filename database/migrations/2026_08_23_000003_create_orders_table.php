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
        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('order_number', 32)->unique(); // e.g. SP-1042
            $table->string('odoo_order_id', 64)->unique();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('restaurant_id', 64)->nullable()->index();
            $table->string('zone_id', 64)->index();
            $table->string('status', 32)->default('awaiting_restaurant_approval')->index();
            $table->string('rejection_reason', 64)->nullable();
            $table->unsignedInteger('prep_minutes')->nullable();
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('delivery_fee_minor')->default(5000);
            $table->bigInteger('discount_minor')->default(0);
            $table->string('payment_method', 32)->default('cash');
            $table->string('payment_status', 32)->default('pending');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('decided_at')->nullable();
            $table->json('payload'); // items, address, notes
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
