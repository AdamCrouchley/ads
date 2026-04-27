<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\ReceivedEvent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Receives booking-confirmation webhooks from any of the four Laravel
 * apps in the Wilberforce Offroad portfolio.
 *
 * The endpoint is intentionally simple: verify, store, return. No
 * Google Ads upload here — that's a separate downstream job that
 * reads from the received_events table.
 *
 * Security model:
 *   - HMAC-SHA256 signature in X-Wilberforce-Signature header
 *   - Per-brand shared secret, never the same across brands
 *   - Constant-time signature comparison
 *   - Replay protection via event age limit
 *   - Idempotency via unique event_id
 *
 * Failure modes return appropriate HTTP codes so the firing app's
 * retry logic does the right thing:
 *   - 4xx: never retry (signature wrong, payload malformed, etc.)
 *   - 5xx: retry with backoff (database down, etc.)
 *   - 200 with status:duplicate: retry succeeded after we already had it
 */
class BookingConfirmedReceiver extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // 1. Required headers
        $brand = $request->header('X-Wilberforce-Brand');
        $signature = $request->header('X-Wilberforce-Signature');
        $eventId = $request->header('X-Wilberforce-Event-Id');

        if (!$brand || !$signature || !$eventId) {
            return $this->reject('missing_required_headers', 400);
        }

        // 2. Brand must be configured
        $config = config("webhooks.brands.{$brand}");
        if (!$config || empty($config['secret'])) {
            // Logged at warning so we know if something's misrouted
            Log::warning('Webhook from unknown brand', ['brand' => $brand, 'event_id' => $eventId]);
            return $this->reject('unknown_brand', 400);
        }

        // 3. Signature verification — uses raw body, not parsed payload,
        // because JSON re-encoding doesn't round-trip identically.
        $rawBody = $request->getContent();
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $rawBody, $config['secret']);

        if (!hash_equals($expectedSignature, $signature)) {
            // Don't say *why* it failed in the response — leaks info.
            // Log it though so we can debug legitimate misconfigurations.
            Log::warning('Webhook signature mismatch', [
                'brand' => $brand,
                'event_id' => $eventId,
                'source_ip' => $request->ip(),
            ]);
            return $this->reject('invalid_signature', 401);
        }

        // 4. Parse payload
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $this->reject('invalid_json', 400);
        }

        // 5. Schema version must be one we know
        $schemaVersion = $payload['schema_version'] ?? null;
        if (!in_array($schemaVersion, config('webhooks.accepted_schema_versions'), true)) {
            return $this->reject('unsupported_schema_version', 400);
        }

        // 6. Event type must be one we accept
        $eventType = $payload['event_type'] ?? null;
        if (!in_array($eventType, config('webhooks.accepted_event_types'), true)) {
            return $this->reject('unsupported_event_type', 400);
        }

        // 7. Brand in header must match brand in payload
        if (($payload['brand'] ?? null) !== $brand) {
            return $this->reject('brand_mismatch', 400);
        }

        // 8. Event ID in header must match event ID in payload
        if (($payload['event_id'] ?? null) !== $eventId) {
            return $this->reject('event_id_mismatch', 400);
        }

        // 9. Replay protection — reject very old events
        $occurredAt = isset($payload['occurred_at']) ? strtotime($payload['occurred_at']) : null;
        if (!$occurredAt) {
            return $this->reject('missing_occurred_at', 400);
        }
        $maxAgeSeconds = config('webhooks.max_event_age_hours', 24) * 3600;
        if ((time() - $occurredAt) > $maxAgeSeconds) {
            return $this->reject('event_too_old', 400);
        }

        // 10. Test events are accepted but not stored — they exist to
        // verify the wiring without polluting real data.
        if ($eventType === 'booking.confirmed.test') {
            return response()->json([
                'status' => 'accepted',
                'event_id' => $eventId,
                'mode' => 'test',
            ], 200);
        }

        // 11. Idempotency — has this event_id been seen before?
        $existing = ReceivedEvent::where('event_id', $eventId)->first();
        if ($existing) {
            return response()->json([
                'status' => 'duplicate',
                'event_id' => $eventId,
            ], 200);
        }

        // 12. Store. Wrap in try/catch so we return 5xx (retryable) on
        // database errors rather than 4xx (which would lose the event).
        try {
            $attribution = $payload['attribution'] ?? [];
            $booking = $payload['booking'] ?? [];
            $value = $booking['value'] ?? [];

            $hasClickId = !empty($attribution['gclid'])
                || !empty($attribution['gbraid'])
                || !empty($attribution['wbraid']);

            ReceivedEvent::create([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'schema_version' => $schemaVersion,
                'brand' => $brand,
                'payload' => $payload,

                'booking_reference' => $booking['id'] ?? null,
                'gclid' => $attribution['gclid'] ?? null,
                'gbraid' => $attribution['gbraid'] ?? null,
                'wbraid' => $attribution['wbraid'] ?? null,
                'value_amount' => $value['amount'] ?? null,
                'value_currency' => $value['currency'] ?? null,
                'event_occurred_at' => $payload['occurred_at'],

                'source_ip' => $request->ip(),
                'received_at' => now(),

                'processing_status' => $hasClickId
                    ? ReceivedEvent::STATUS_PENDING
                    : ReceivedEvent::STATUS_SKIPPED_NO_CLICKID,
            ]);
        } catch (\Throwable $e) {
            // If the unique constraint fires here (race between the check
            // and the insert), treat as duplicate.
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE')) {
                return response()->json([
                    'status' => 'duplicate',
                    'event_id' => $eventId,
                ], 200);
            }

            Log::error('Webhook storage failed', [
                'event_id' => $eventId,
                'brand' => $brand,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => 'error',
                'reason' => 'storage_failed',
            ], 500);
        }

        return response()->json([
            'status' => 'accepted',
            'event_id' => $eventId,
        ], 200);
    }

    private function reject(string $reason, int $status): JsonResponse
    {
        return response()->json([
            'status' => 'rejected',
            'reason' => $reason,
        ], $status);
    }
}
