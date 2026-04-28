<?php

/*
|--------------------------------------------------------------------------
| Google Ads API configuration
|--------------------------------------------------------------------------
|
| This file is the canonical place where the application reads Google Ads
| API settings. All values come from environment variables — secrets and
| identifiers are never hardcoded in code.
|
| Production environment variables required (set in Forge):
|
|   GOOGLE_ADS_DEVELOPER_TOKEN       — from MCC > Tools > API Center
|   GOOGLE_ADS_LOGIN_CUSTOMER_ID     — your MCC ID, digits only (e.g. 1354542535)
|   GOOGLE_OAUTH_CLIENT_ID           — from Google Cloud > Credentials
|   GOOGLE_OAUTH_CLIENT_SECRET       — from Google Cloud > Credentials
|
| Local dev environment can use the same values (developer token works
| against your real accounts in basic mode), but use a SEPARATE OAuth
| client if you want to test the auth flow without touching production
| connected_accounts data.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Developer token
    |--------------------------------------------------------------------------
    | Approved by Google for "Basic access" — 15,000 ops/day, sufficient for
    | our volume. Never log this. Never commit it.
    */
    'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Login customer ID
    |--------------------------------------------------------------------------
    | Sent as the `login-customer-id` HTTP header on every API call. Tells
    | Google Ads which manager account is authorising the request — required
    | when calling the API on behalf of accounts under an MCC.
    */
    'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),

    /*
    |--------------------------------------------------------------------------
    | OAuth credentials
    |--------------------------------------------------------------------------
    | Used during the authorisation flow at /auth/google/callback. The
    | resulting refresh token is stored encrypted on connected_accounts.
    */
    'oauth_client_id' => env('GOOGLE_OAUTH_CLIENT_ID'),
    'oauth_client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET'),
    'oauth_redirect_uri' => env('APP_URL') . '/auth/google/callback',

    /*
    |--------------------------------------------------------------------------
    | API endpoints
    |--------------------------------------------------------------------------
    | Pinned to a known-good API version. Bump when migrating to a newer
    | version — always test against staging first.
    */
    'api_version' => 'v18',
    'base_url' => 'https://googleads.googleapis.com',

    /*
    |--------------------------------------------------------------------------
    | Upload behaviour
    |--------------------------------------------------------------------------
    | Tunable knobs for the conversion uploader. Defaults match what was
    | agreed at design time (5 min frequency, 50 events/batch, 60-day cutoff).
    */
    'upload' => [
        'batch_size' => 50,
        'max_age_days' => 60,
        'request_timeout_seconds' => 30,
    ],

];
