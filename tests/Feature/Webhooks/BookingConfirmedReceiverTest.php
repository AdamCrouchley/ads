<?php

namespace Tests\Feature\Webhooks;

use App\Models\ReceivedEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Tests for the booking-confirmation webhook receiver.
 *
 * Every security check has a test. Every state transition has a test.
 * If a test here fails, the data foundation is broken — fix it before
 * shipping anything else.
 */
class BookingConfirmedReceiverTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private string $brand = 'jimny_nz';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set("webhooks.brands.{$this->brand}.secret", $this->secret);
        Config::set('webhooks.accepted_schema_versions', ['1']);
        Config::set('webhooks.accepted_event_types', ['booking.confirmed', 'booking.confirmed.test']);
        Config::set('webhooks.max_event_age_hours', 24);
    }

    /** @test */
    public function it_accepts_a_valid_webhook(): void
    {
        $payload = $this->validPayload();
        $response = $this->sendSigned($payload);

        $response->assertStatus(200)->assertJson(['status' => 'accepted']);

        $this->assertDatabaseHas('received_events', [
            'event_id' => $payload['event_id'],
            'brand' => $this->brand,
            'gclid' => 'Cj0KCQjw_test_gclid',
            'value_amount' => 1247.50,
            'processing_status' => ReceivedEvent::STATUS_PENDING,
        ]);
    }

    /** @test */
    public function it_marks_events_without_click_ids_as_skipped(): void
    {
        $payload = $this->validPayload();
        $payload['attribution']['gclid'] = null;
        $payload['attribution']['gbraid'] = null;
        $payload['attribution']['wbraid'] = null;

        $this->sendSigned($payload)->assertStatus(200);

        $this->assertDatabaseHas('received_events', [
            'event_id' => $payload['event_id'],
            'processing_status' => ReceivedEvent::STATUS_SKIPPED_NO_CLICKID,
        ]);
    }

    /** @test */
    public function it_handles_test_events_without_storing(): void
    {
        $payload = $this->validPayload();
        $payload['event_type'] = 'booking.confirmed.test';

        $this->sendSigned($payload)
            ->assertStatus(200)
            ->assertJson(['status' => 'accepted', 'mode' => 'test']);

        $this->assertDatabaseCount('received_events', 0);
    }

    /** @test */
    public function it_rejects_missing_signature(): void
    {
        $payload = $this->validPayload();
        $body = json_encode($payload);

        $this->postJson(
            '/webhooks/booking-confirmed',
            $payload,
            [
                'X-Wilberforce-Brand' => $this->brand,
                'X-Wilberforce-Event-Id' => $payload['event_id'],
            ]
        )->assertStatus(400);
    }

    /** @test */
    public function it_rejects_invalid_signature(): void
    {
        $payload = $this->validPayload();
        $body = json_encode($payload);

        $server = array_merge(
            $this->transformHeadersToServerVars([
                'X-Wilberforce-Brand' => $this->brand,
                'X-Wilberforce-Event-Id' => $payload['event_id'],
                'X-Wilberforce-Signature' => 'sha256=' . str_repeat('0', 64),
            ]),
            ['CONTENT_TYPE' => 'application/json']
        );

        $this->call('POST', '/webhooks/booking-confirmed', [], [], [], $server, $body)
            ->assertStatus(401);

        $this->assertDatabaseCount('received_events', 0);
    }

    /** @test */
    public function it_rejects_unknown_brand(): void
    {
        $payload = $this->validPayload();
        $payload['brand'] = 'definitely_not_a_real_brand';

        $body = json_encode($payload);
        $sig = 'sha256=' . hash_hmac('sha256', $body, $this->secret);

        $server = array_merge(
            $this->transformHeadersToServerVars([
                'X-Wilberforce-Brand' => 'definitely_not_a_real_brand',
                'X-Wilberforce-Event-Id' => $payload['event_id'],
                'X-Wilberforce-Signature' => $sig,
            ]),
            ['CONTENT_TYPE' => 'application/json']
        );

        $this->call('POST', '/webhooks/booking-confirmed', [], [], [], $server, $body)
            ->assertStatus(400);
    }

    /** @test */
    public function it_rejects_unsupported_schema_version(): void
    {
        $payload = $this->validPayload();
        $payload['schema_version'] = '99';

        $this->sendSigned($payload)->assertStatus(400);
    }

    /** @test */
    public function it_rejects_unsupported_event_type(): void
    {
        $payload = $this->validPayload();
        $payload['event_type'] = 'something.else';

        $this->sendSigned($payload)->assertStatus(400);
    }

    /** @test */
    public function it_rejects_brand_header_payload_mismatch(): void
    {
        $payload = $this->validPayload();
        $body = json_encode($payload);
        $sig = 'sha256=' . hash_hmac('sha256', $body, $this->secret);

        // Header says jimny_nz, payload says jimny_nz, but we override
        // the header to dream_drives — should reject because the
        // signed body says jimny_nz.
        Config::set('webhooks.brands.dream_drives.secret', 'different_secret');

        $server = array_merge(
            $this->transformHeadersToServerVars([
                'X-Wilberforce-Brand' => 'dream_drives',
                'X-Wilberforce-Event-Id' => $payload['event_id'],
                'X-Wilberforce-Signature' => $sig,
            ]),
            ['CONTENT_TYPE' => 'application/json']
        );

        $this->call('POST', '/webhooks/booking-confirmed', [], [], [], $server, $body)
            ->assertStatus(401); // signature won't verify against dream_drives secret
    }

    /** @test */
    public function it_rejects_event_id_header_payload_mismatch(): void
    {
        $payload = $this->validPayload();
        $body = json_encode($payload);
        $sig = 'sha256=' . hash_hmac('sha256', $body, $this->secret);

        $server = array_merge(
            $this->transformHeadersToServerVars([
                'X-Wilberforce-Brand' => $this->brand,
                'X-Wilberforce-Event-Id' => 'evt_different',
                'X-Wilberforce-Signature' => $sig,
            ]),
            ['CONTENT_TYPE' => 'application/json']
        );

        $this->call('POST', '/webhooks/booking-confirmed', [], [], [], $server, $body)
            ->assertStatus(400);
    }

    /** @test */
    public function it_rejects_old_events(): void
    {
        $payload = $this->validPayload();
        $payload['occurred_at'] = now()->subHours(48)->toIso8601String();

        $this->sendSigned($payload)->assertStatus(400);
    }

    /** @test */
    public function it_returns_duplicate_for_replayed_events(): void
    {
        $payload = $this->validPayload();

        // First delivery accepted
        $this->sendSigned($payload)->assertStatus(200)->assertJson(['status' => 'accepted']);

        // Second delivery with same event_id returns duplicate
        $this->sendSigned($payload)->assertStatus(200)->assertJson(['status' => 'duplicate']);

        $this->assertDatabaseCount('received_events', 1);
    }

    /** @test */
    public function it_rejects_invalid_json(): void
    {
        $body = 'this is not json';
        $sig = 'sha256=' . hash_hmac('sha256', $body, $this->secret);

        $server = array_merge(
            $this->transformHeadersToServerVars([
                'X-Wilberforce-Brand' => $this->brand,
                'X-Wilberforce-Event-Id' => 'evt_test',
                'X-Wilberforce-Signature' => $sig,
            ]),
            ['CONTENT_TYPE' => 'application/json']
        );

        $this->call('POST', '/webhooks/booking-confirmed', [], [], [], $server, $body)
            ->assertStatus(400);
    }

    // ---- helpers ----

    private function validPayload(): array
    {
        return [
            'schema_version' => '1',
            'event_type' => 'booking.confirmed',
            'event_id' => 'evt_' . uniqid(),
            'occurred_at' => now()->toIso8601String(),
            'brand' => $this->brand,
            'booking' => [
                'id' => 'BK-2026-04829',
                'status' => 'confirmed',
                'confirmed_at' => now()->toIso8601String(),
                'value' => ['amount' => 1247.50, 'currency' => 'NZD', 'includes_gst' => true],
                'vehicle' => ['id' => 'veh_jimny_009', 'type' => 'Jimny Sierra Manual'],
                'dates' => [
                    'pickup' => now()->addDays(14)->toIso8601String(),
                    'dropoff' => now()->addDays(17)->toIso8601String(),
                ],
                'customer' => ['country' => 'NZ', 'is_new_customer' => true],
            ],
            'attribution' => [
                'gclid' => 'Cj0KCQjw_test_gclid',
                'gbraid' => null,
                'wbraid' => null,
                'fbclid' => null,
                'first_seen_at' => now()->subDays(3)->toIso8601String(),
                'landing_url' => 'https://jimny.co.nz/christchurch?gclid=Cj0KCQjw_test_gclid',
                'form_submitted_at' => now()->subHours(2)->toIso8601String(),
            ],
        ];
    }

    private function sendSigned(array $payload)
    {
        $body = json_encode($payload);
        $sig = 'sha256=' . hash_hmac('sha256', $body, $this->secret);

        $server = array_merge(
            $this->transformHeadersToServerVars([
                'X-Wilberforce-Brand' => $payload['brand'],
                'X-Wilberforce-Event-Id' => $payload['event_id'],
                'X-Wilberforce-Signature' => $sig,
            ]),
            ['CONTENT_TYPE' => 'application/json']
        );

        return $this->call(
            'POST',
            '/webhooks/booking-confirmed',
            [],
            [],
            [],
            $server,
            $body
        );
    }
}
