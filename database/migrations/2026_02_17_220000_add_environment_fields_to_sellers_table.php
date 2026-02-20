<?php

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
        Schema::table('sellers', function (Blueprint $table) {
            $table->string('email')->nullable()->after('company_name');
            $table->string('environment_url')->nullable()->after('email')->comment('Primary domain of the seller training environment');
            $table->string('environment_name')->nullable()->after('environment_url');
            $table->string('logo_url')->nullable()->after('environment_name');
            $table->unsignedBigInteger('environment_id')->nullable()->after('logo_url')->comment('Environment ID from Main API');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->dropColumn(['email', 'environment_url', 'environment_name', 'logo_url', 'environment_id']);
        });
    }
};
