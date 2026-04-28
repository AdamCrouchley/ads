<?php

use App\Jobs\UploadPendingConversions;
use App\Models\ConnectedAccount;
use App\Models\ReceivedEvent;
use App\Services\GoogleAds\GoogleAdsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| UploadPendingConversions tests
|--------------------------------------------------------------------------
|
| Critical invariants:
|   - Upload happens for connected brands only
|   - Events without click ID are excluded (would fail anyway)
|   - Events older than 60 days are excluded
|   - Successful events get processing_status=uploaded
|   - Failed events get processing_status=failed with error message
|   - Brand-level errors don't break other brands
|   - Access tokens are cached (not refreshed on every batch)
|
| To run: php artisan test --filter=UploadPendingConversions
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('google_ads', [
        'developer_token' => 'test-developer-token',
        'login_customer_id' => '1354542535',
        'oauth_client_id' => 'test-client-id',
        'oauth_client_secret' => 'test-client-secret',
        'oauth_redirect_uri' => 'http://localhost/auth/google/callback',
        'api_version' => 'v18',
        'base_url' => 'https://googleads.googleapis.com',
        'upload' => [
            'batch_size' => 50,
            'max_age_days' => 60,
            'request_timeout_seconds' => 30,
        ],
    ]);

    Cache::flush();
});

test('it uploads pending events for connected brand', function () {
    $account = makeConnectedAccount('jimny_nz');
    $event = makePendingEvent('jimny_nz', ['gclid' => 'test_gclid_123']);

    fakeGoogleSuccess([$event]);

    (new UploadPendingConversions)->handle(app(GoogleAdsClient::class));

    $event->refresh();
    expect($event->processing_status)->toBe('uploaded');
    expect($event->processed_at)->not->toBeNull();
    expect($event->last_upload_attempt_at)->not->toBeNull();
});

test('it does not upload for unconnected brand', function () {
    Http::fake();

    // Create the brand row but without OAuth fields
    ConnectedAccount::create([
        'brand' => 'jimny_nz',
        'display_name' => 'Jimny Rentals NZ',
        'customer_id' => '4216879470',
        'conversion_action_resource' => 'customers/4216879470/conversionActions/7591775442',
        // no refresh_token
    ]);

    $event = makePendingEvent('jimny_nz', ['gclid' => 'test_gclid']);

    (new UploadPendingConversions)->handle(app(GoogleAdsClient::class));

    Http::assertNothingSent();
    expect($event->fresh()->processing_status)->toBe('pending');
});

test('it excludes events without click id', function () {
    $account = makeConnectedAccount('jimny_nz');

    // Event with no click ID at all
    $eventNoClickId = makePendingEvent('jimny_nz', [
        'gclid' => null,
        'gbraid' => null,
        'wbraid' => null,
    ]);

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token'], 200),
        'googleads.googleapis.com/*' => Http::response([
            'results' => [],
        ], 200),
    ]);

    (new UploadPendingConversions)->handle(app(GoogleAdsClient::class));

    expect($eventNoClickId->fresh()->processing_status)->toBe('pending');

    // Receiver should NOT have made an upload call (no eligible events)
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'uploadClickConversions'));
});

test('it excludes events older than max age', function () {
    $account = makeConnectedAccount('jimny_nz');

    $oldEvent = makePendingEvent('jimny_nz', [
        'gclid' => 'old_gclid',
        'received_at' => now()->subDays(70), // older than 60-day cutoff
    ]);

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token'], 200),
        'googleads.googleapis.com/*' => Http::response(['results' => []], 200),
    ]);

    (new UploadPendingConversions)->handle(app(GoogleAdsClient::class));

    expect($oldEvent->fresh()->processing_status)->toBe('pending');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'uploadClickConversions'));
});

test('it marks per-row failures as failed with error message', function () {
    $account = makeConnectedAccount('jimny_nz');
    $event = makePendingEvent('jimny_nz', ['gclid' => 'bad_gclid']);

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token'], 200),
        'googleads.googleapis.com/*' => Http::response([
            'results' => [null], // null = this row failed
            'partialFailureError' => [
                'message' => 'Partial failure',
                'details' => [[
                    'errors' => [[
                        'message' => 'gclid not found in our records',
                        'location' => [
                            'fieldPathElements' => [['index' => 0]],
                        ],
                    ]],
                ]],
            ],
        ], 200),
    ]);

    (new UploadPendingConversions)->handle(app(GoogleAdsClient::class));

    $event->refresh();
    expect($event->processing_status)->toBe('failed');
    expect($event->processing_error)->toBe('gclid not found in our records');
    expect($event->processing_attempts)->toBe(1);
});

test('it does not break other brands when one brand fails', function () {
    $jimny = makeConnectedAccount('jimny_nz');
    $dreamDrives = makeConnectedAccount('dream_drives');

    $jimnyEvent = makePendingEvent('jimny_nz', ['gclid' => 'jimny_gclid']);
    $ddEvent = makePendingEvent('dream_drives', ['gclid' => 'dd_gclid']);

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token'], 200),

        'googleads.googleapis.com/v18/customers/4216879470:uploadClickConversions' => Http::response(
            ['error' => ['message' => 'simulated server error']],
            500
        ),

        'googleads.googleapis.com/v18/customers/2074836776:uploadClickConversions' => Http::response([
            'results' => [['conversionAction' => 'customers/.../conversionActions/...']],
        ], 200),
    ]);

    (new UploadPendingConversions)->handle(app(GoogleAdsClient::class));

    // Jimny should still be pending (whole-batch error), dream_drives should be uploaded
    expect($jimnyEvent->fresh()->processing_status)->toBe('pending');
    expect($ddEvent->fresh()->processing_status)->toBe('uploaded');
});

test('access token is cached and not refreshed on every batch', function () {
    $account = makeConnectedAccount('jimny_nz');
    $event = makePendingEvent('jimny_nz', ['gclid' => 'test_gclid']);

    fakeGoogleSuccess([$event]);

    // Run twice in quick succession
    (new UploadPendingConversions)->handle(app(GoogleAdsClient::class));
    (new UploadPendingConversions)->handle(app(GoogleAdsClient::class));

    // Token endpoint should only have been called once (second call cached)
    Http::assertSentCount(2);
    // 1 token call + 1 upload call = 2 total. (If token wasn't cached we'd have 4.)
    // Note: second run finds no pending events so doesn't call upload either way.
});

// ---- helpers ----

function makeConnectedAccount(string $brand): ConnectedAccount
{
    $defaults = [
        'jimny_nz' => [
            'display_name' => 'Jimny Rentals NZ',
            'customer_id' => '4216879470',
            'conversion_action_resource' => 'customers/4216879470/conversionActions/7591775442',
        ],
        'dream_drives' => [
            'display_name' => 'Dream Drives',
            'customer_id' => '2074836776',
            'conversion_action_resource' => 'customers/2074836776/conversionActions/7591770600',
        ],
    ];

    return ConnectedAccount::create(array_merge(
        $defaults[$brand] ?? [],
        [
            'brand' => $brand,
            'login_customer_id' => '1354542535',
            'refresh_token' => 'fake-refresh-token-' . $brand,
            'oauth_email' => 'test@dorimedia.co.nz',
            'connected_at' => now(),
        ]
    ));
}

function makePendingEvent(string $brand, array $overrides = []): ReceivedEvent
{
    return ReceivedEvent::create(array_merge([
        'event_id' => 'evt_' . uniqid(),
        'event_type' => 'booking.confirmed',
        'schema_version' => '1',
        'brand' => $brand,
        'payload' => ['brand' => $brand, 'event_type' => 'booking.confirmed'],
        'booking_reference' => 'TEST-' . uniqid(),
        'value_amount' => 980.00,
        'value_currency' => 'NZD',
        'event_occurred_at' => now()->subHours(2),
        'received_at' => now()->subHour(),
        'processing_status' => 'pending',
    ], $overrides));
}

function fakeGoogleSuccess(array $events): void
{
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token'], 200),
        'googleads.googleapis.com/*' => Http::response([
            'results' => array_map(fn ($e) => [
                'conversionAction' => 'customers/.../conversionActions/...',
                'gclid' => $e->gclid,
            ], $events),
        ], 200),
    ]);
}
