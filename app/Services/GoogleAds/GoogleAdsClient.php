<?php

namespace App\Services\GoogleAds;

use App\Models\ConnectedAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin client over the Google Ads API REST endpoint for offline conversion
 * uploads. Avoids the heavy `googleads/google-ads-php` SDK dependency for v1
 * because we only call ONE endpoint (uploadClickConversions). Plain HTTP +
 * the receiver-side patterns we already use is simpler to reason about.
 *
 * Two responsibilities:
 *
 *   1. Get a fresh access token from a stored refresh token (cached for ~50
 *      minutes since Google issues 1-hour tokens).
 *
 *   2. POST a batch of click conversions to Google Ads and parse the response.
 *
 * If we later need other API calls (campaign reads, keyword changes), THIS is
 * the file to extend — keep API access centralised here so logging, retry,
 * and rate-limit handling stay consistent.
 */
class GoogleAdsClient
{
    /**
     * Get a fresh OAuth access token for the given connected account.
     *
     * Cached per-account for 50 minutes (Google tokens expire after 60 — we
     * leave a 10-minute safety buffer).
     */
    public function accessTokenFor(ConnectedAccount $account): string
    {
        $cacheKey = 'google_ads_access_token:' . $account->id;

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($account) {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('google_ads.oauth_client_id'),
                'client_secret' => config('google_ads.oauth_client_secret'),
                'refresh_token' => $account->refresh_token,
                'grant_type' => 'refresh_token',
            ]);

            if (!$response->successful()) {
                Log::error('Google Ads token refresh failed', [
                    'brand' => $account->brand,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new RuntimeException(
                    "Token refresh failed for {$account->brand}: HTTP {$response->status()}. "
                    . "The refresh token may have been revoked — try reconnecting the brand."
                );
            }

            $token = $response->json('access_token');
            if (!$token) {
                throw new RuntimeException("Token refresh response missing access_token for {$account->brand}");
            }

            return $token;
        });
    }

    /**
     * Upload a batch of click conversions for a given brand.
     *
     * Returns an array describing per-row results, in the same order as input:
     *   [
     *     ['success' => true,  'resource_name' => 'customers/.../conversions/...', 'error' => null],
     *     ['success' => false, 'resource_name' => null, 'error' => 'gclid not found'],
     *     ...
     *   ]
     *
     * @param array $conversions Each row: [
     *     'gclid' => string|null,
     *     'gbraid' => string|null,
     *     'wbraid' => string|null,
     *     'conversion_value' => float,
     *     'conversion_currency' => string (e.g. 'NZD'),
     *     'conversion_date_time' => string (e.g. '2026-04-27 10:30:00+12:00'),
     *     'order_id' => string (the booking_ref — used for dedupe on Google's side),
     *   ]
     */
    public function uploadClickConversions(ConnectedAccount $account, array $conversions): array
    {
        if (empty($conversions)) {
            return [];
        }

        $accessToken = $this->accessTokenFor($account);
        $apiVersion = config('google_ads.api_version');
        $baseUrl = config('google_ads.base_url');
        $customerId = $account->customer_id;
        $loginCustomerId = $account->login_customer_id ?? config('google_ads.login_customer_id');

        $url = "{$baseUrl}/{$apiVersion}/customers/{$customerId}:uploadClickConversions";

        $payload = [
            'conversions' => array_map(
                fn ($c) => $this->buildConversion($c, $account->conversion_action_resource),
                $conversions
            ),
            'partialFailure' => true,    // continue uploading other rows if some fail
            'validateOnly' => false,
        ];

        $response = Http::withToken($accessToken)
            ->withHeaders([
                'developer-token' => config('google_ads.developer_token'),
                'login-customer-id' => $loginCustomerId,
                'Content-Type' => 'application/json',
            ])
            ->timeout(config('google_ads.upload.request_timeout_seconds'))
            ->post($url, $payload);

        if (!$response->successful()) {
            // Whole-batch failure (auth, network, malformed request). Caller decides
            // whether to retry — we just surface the error.
            Log::error('Google Ads uploadClickConversions failed', [
                'brand' => $account->brand,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException(
                "uploadClickConversions failed for {$account->brand}: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $this->parseResponse($response->json(), count($conversions));
    }

    /**
     * Build a single ClickConversion request entry.
     *
     * Exactly one of gclid/gbraid/wbraid must be present. Caller is responsible
     * for filtering events that have none.
     */
    private function buildConversion(array $c, string $conversionActionResource): array
    {
        $base = [
            'conversionAction' => $conversionActionResource,
            'conversionDateTime' => $c['conversion_date_time'],
            'conversionValue' => (float) $c['conversion_value'],
            'currencyCode' => $c['conversion_currency'] ?? 'NZD',
            'orderId' => $c['order_id'] ?? null,
        ];

        // Click identifier — gclid takes precedence, then gbraid, then wbraid.
        if (!empty($c['gclid'])) {
            $base['gclid'] = $c['gclid'];
        } elseif (!empty($c['gbraid'])) {
            $base['gbraid'] = $c['gbraid'];
        } elseif (!empty($c['wbraid'])) {
            $base['wbraid'] = $c['wbraid'];
        }

        return array_filter($base, fn ($v) => $v !== null);
    }

    /**
     * Parse a successful uploadClickConversions response into per-row results.
     *
     * Google returns:
     *   {
     *     "results": [{ "gclid": "...", "conversionAction": "...", ... }, null, ...],
     *     "partialFailureError": { "details": [{ "errors": [{"message": "...", "location": {...}}]}] }
     *   }
     *
     * Failed rows appear as null in `results`, with errors in partialFailureError.
     * The error's `location` includes the input index so we can map back to rows.
     */
    private function parseResponse(array $body, int $expectedCount): array
    {
        $results = $body['results'] ?? array_fill(0, $expectedCount, null);
        $errorsByIndex = $this->extractPartialFailureErrors($body['partialFailureError'] ?? null);

        $output = [];
        for ($i = 0; $i < $expectedCount; $i++) {
            $row = $results[$i] ?? null;
            if (is_array($row) && !empty($row)) {
                // Successful — Google returns the conversion identifiers it accepted.
                // The "resource name" we store is a stable identifier for this conversion.
                $output[] = [
                    'success' => true,
                    'resource_name' => $row['conversionAction'] ?? null,
                    'gclid' => $row['gclid'] ?? null,
                    'error' => null,
                ];
            } else {
                $output[] = [
                    'success' => false,
                    'resource_name' => null,
                    'gclid' => null,
                    'error' => $errorsByIndex[$i] ?? 'Unknown error (no detail returned for this row)',
                ];
            }
        }

        return $output;
    }

    /**
     * Pull per-index error messages out of a partialFailureError envelope.
     *
     * Google's protobuf-style error structure is verbose; we extract just the
     * useful bit: index → error message.
     */
    private function extractPartialFailureErrors($partialFailureError): array
    {
        if (!is_array($partialFailureError)) {
            return [];
        }

        $byIndex = [];
        $details = $partialFailureError['details'] ?? [];

        foreach ($details as $detail) {
            $errors = $detail['errors'] ?? [];
            foreach ($errors as $error) {
                $idx = $error['location']['fieldPathElements'][0]['index'] ?? null;
                if ($idx !== null) {
                    $byIndex[(int) $idx] = $error['message'] ?? 'Unknown error';
                }
            }
        }

        return $byIndex;
    }
}
