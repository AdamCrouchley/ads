<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores every webhook received from the four Laravel apps.
 *
 * This table is the staging area for offline conversion uploads. The
 * receiver writes here; the conversion uploader (built later) reads
 * from here and pushes to Google Ads, then marks rows as processed.
 *
 * Idempotency: event_id has a unique constraint. Duplicate deliveries
 * silently no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('received_events', function (Blueprint $table) {
            $table->id();

            // Identification + idempotency
            $table->string('event_id', 50)->unique();
            $table->string('event_type', 50)->index();
            $table->string('schema_version', 10);
            $table->string('brand', 30)->index();

            // Raw payload — keep everything we received, JSON column.
            // Source of truth for any future replay or reprocessing.
            $table->json('payload');

            // Extracted fields (denormalised for quick querying without
            // unpacking the JSON every time). Populated from payload on
            // insert.
            $table->string('booking_reference', 100)->nullable()->index();
            $table->string('gclid', 200)->nullable()->index();
            $table->string('gbraid', 200)->nullable();
            $table->string('wbraid', 200)->nullable();
            $table->decimal('value_amount', 12, 2)->nullable();
            $table->string('value_currency', 3)->nullable();
            $table->timestamp('event_occurred_at')->nullable()->index();

            // Receipt metadata
            $table->ipAddress('source_ip')->nullable();
            $table->timestamp('received_at');

            // Forward processing state — the conversion uploader uses these
            // States: pending, uploaded, skipped_no_clickid, failed
            $table->string('processing_status', 30)->default('pending')->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->unsignedTinyInteger('processing_attempts')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('received_events');
    }
};
