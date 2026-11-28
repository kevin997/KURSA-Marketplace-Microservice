<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_payout_accounts', function (Blueprint $table) {
            $table->id();
            // Retain the financial snapshot if merchandising/account records are removed.
            $table->unsignedBigInteger('seller_id');
            $table->char('currency', 3);
            foreach (['held', 'available', 'reserved', 'settled', 'first_party'] as $bucket) {
                $table->unsignedBigInteger($bucket)->default(0);
            }
            $table->boolean('frozen')->default(false);
            $table->unique(['seller_id', 'currency']);
            $table->timestamps();
        });

        Schema::create('marketplace_payout_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('marketplace_payout_accounts')->restrictOnDelete();
            $table->char('request_key', 64);
            $table->unsignedBigInteger('amount');
            $table->string('status', 20);
            $table->unique(['account_id', 'request_key']);
            $table->timestamps();
        });

        Schema::create('marketplace_payout_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('marketplace_payout_accounts')->restrictOnDelete();
            $table->foreignId('payout_request_id')->nullable()->constrained('marketplace_payout_requests')->restrictOnDelete();
            $table->char('event_key', 64)->unique();
            $table->char('evidence_hash', 64);
            $table->string('kind', 30);
            $table->unsignedBigInteger('amount');
            $table->string('from_bucket', 20)->nullable();
            $table->string('to_bucket', 20)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason', 300)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_payout_ledger_entries');
        Schema::dropIfExists('marketplace_payout_requests');
        Schema::dropIfExists('marketplace_payout_accounts');
    }
};
