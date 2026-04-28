<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Adds Google Ads upload tracking columns to received_events.
     *
     *   google_ads_resource_name — Google's identifier for the uploaded conversion
     *     (returned in the API response). Useful for debugging and for adjustment
     *     uploads later (cancellations, refunds).
     *
     *   last_upload_attempt_at — when the uploader last touched this row.
     *     Helpful for diagnosing stuck-pending events.
     *
     *   upload_response_summary — JSON blob with the raw API response on the
     *     last attempt. Trimmed to essentials so we don't bloat the table.
     */
    public function up(): void
    {
        Schema::table('received_events', function (Blueprint $table) {
            $table->string('google_ads_resource_name', 200)->nullable()->after('processed_at');
            $table->timestamp('last_upload_attempt_at')->nullable()->after('google_ads_resource_name');
            $table->json('upload_response_summary')->nullable()->after('last_upload_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('received_events', function (Blueprint $table) {
            $table->dropColumn([
                'google_ads_resource_name',
                'last_upload_attempt_at',
                'upload_response_summary',
            ]);
        });
    }
};
