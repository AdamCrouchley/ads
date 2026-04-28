<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Stores OAuth credentials and Google Ads identifiers per brand.
     *
     * One row per brand that has authorised the dashboard. The refresh
     * token is encrypted at rest via Laravel's `encrypted` cast on the
     * model. Access tokens are not persisted — they're short-lived and
     * regenerated from the refresh token at upload time.
     */
    public function up(): void
    {
        Schema::create('connected_accounts', function (Blueprint $table) {
            $table->id();

            // Brand identifier matches what the receiver uses (jimny_nz, dream_drives).
            $table->string('brand', 50)->unique();

            // Optional human label for the dashboard.
            $table->string('display_name', 100)->nullable();

            // Google Ads identifiers (digits only, no dashes).
            $table->string('customer_id', 20)->nullable();
            $table->string('login_customer_id', 20)->nullable()->comment('MCC ID used as login-customer-id header');
            $table->string('conversion_action_resource', 200)->nullable()
                ->comment('e.g. customers/4216879470/conversionActions/7591775442');

            // OAuth — refresh token is encrypted via model cast.
            $table->text('refresh_token')->nullable();
            $table->string('oauth_email', 255)->nullable()->comment('Google account that authorised');
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_upload_at')->nullable();
            $table->string('last_upload_status', 30)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_accounts');
    }
};
