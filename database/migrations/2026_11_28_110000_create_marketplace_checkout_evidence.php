<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_checkout_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('buyer_id');
            $table->string('idempotency_key', 80);
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('template_id');
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('seller_user_id');
            $table->unsignedBigInteger('merchant_environment_id');
            $table->string('product_name');
            $table->unsignedInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('provider_payment_id', 191)->nullable()->unique();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['buyer_id', 'idempotency_key'], 'market_checkout_buyer_key');
            // A second checkout cannot collect for the same template while an existing
            // payment may still settle. Releasing an attempt requires reconciliation.
            $table->unique(['buyer_id', 'template_id'], 'market_checkout_buyer_template');
        });
        Schema::create('marketplace_entitlement_events', function (Blueprint $table) {
            $table->uuid('attempt_id')->primary();
            $table->unsignedBigInteger('buyer_id');
            $table->unsignedBigInteger('template_id');
            $table->unsignedBigInteger('seller_id');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_entitlement_events');
        Schema::dropIfExists('marketplace_checkout_attempts');
    }
};
