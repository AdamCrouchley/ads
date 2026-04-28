<?php

namespace App\Http\Controllers\Auth;

use App\Models\ConnectedAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Google Ads OAuth authorisation flow.
 *
 * Two routes:
 *   GET /auth/google/connect/{brand}  — start: redirect user to Google
 *   GET /auth/google/callback         — return: exchange code for refresh token
 *
 * The flow is keyed by the `brand` parameter so we know which row in
 * connected_accounts to update when the user returns. The brand is stashed
 * in the OAuth `state` parameter (with a CSRF nonce) and verified on return.
 *
 * Authentication: protected by Filament's auth middleware so only logged-in
 * dashboard users can initiate OAuth — prevents anyone hitting the connect
 * URL and re-authorising your accounts.
 */
class GoogleAdsOAuthController extends Controller
{
    private const ALLOWED_BRANDS = ['jimny_nz', 'dream_drives'];
    private const SCOPES = 'https://www.googleapis.com/auth/adwords';

    /**
     * Redirect the user to Google's OAuth consent screen.
     *
     * The state parameter encodes both:
     *   - a random nonce stored in session (CSRF protection)
     *   - the brand we're authorising
     */
    public function connect(Request $request, string $brand): RedirectResponse
    {
        if (!in_array($brand, self::ALLOWED_BRANDS, true)) {
            abort(404);
        }

        $nonce = Str::random(40);
        $state = $nonce . ':' . $brand;
        $request->session()->put('google_oauth_nonce', $nonce);

        $params = http_build_query([
            'client_id' => config('google_ads.oauth_client_id'),
            'redirect_uri' => config('google_ads.oauth_redirect_uri'),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',         // ensures we get a refresh_token
            'prompt' => 'consent',              // force the consent screen so we always get a refresh_token
            'state' => $state,
        ]);

        return redirect('https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    }

    /**
     * Handle Google's callback. Exchange the authorisation code for a
     * refresh token and store it (encrypted) on the matching brand row.
     */
    public function callback(Request $request): RedirectResponse
    {
        $dashboardUrl = config('app.url') . '/dashboard';

        // Google may redirect with an error (user denied, etc).
        if ($request->has('error')) {
            return redirect($dashboardUrl . '/accounts')
                ->with('error', 'Google Ads authorisation cancelled or failed: ' . $request->query('error'));
        }

        $code = $request->query('code');
        $state = $request->query('state');

        if (!$code || !$state) {
            return redirect($dashboardUrl . '/accounts')
                ->with('error', 'OAuth callback missing required parameters.');
        }

        // Validate state.
        $expectedNonce = $request->session()->pull('google_oauth_nonce');
        [$nonce, $brand] = array_pad(explode(':', $state, 2), 2, null);

        if (!hash_equals((string) $expectedNonce, (string) $nonce)) {
            Log::warning('Google OAuth state mismatch', ['received_state' => $state]);
            return redirect($dashboardUrl . '/accounts')
                ->with('error', 'OAuth state mismatch — please try connecting again.');
        }

        if (!in_array($brand, self::ALLOWED_BRANDS, true)) {
            return redirect($dashboardUrl . '/accounts')
                ->with('error', 'Unknown brand in OAuth callback.');
        }

        // Exchange code for tokens.
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('google_ads.oauth_client_id'),
            'client_secret' => config('google_ads.oauth_client_secret'),
            'redirect_uri' => config('google_ads.oauth_redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);

        if (!$response->successful()) {
            Log::error('Google OAuth token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'brand' => $brand,
            ]);
            return redirect($dashboardUrl . '/accounts')
                ->with('error', 'Token exchange with Google failed. Check logs.');
        }

        $data = $response->json();
        $refreshToken = $data['refresh_token'] ?? null;

        if (!$refreshToken) {
            // Without prompt=consent, Google won't return a new refresh_token if the
            // user has already granted consent before. We forced prompt=consent above
            // specifically to avoid this — but log it just in case.
            Log::error('Google OAuth response missing refresh_token', [
                'brand' => $brand,
                'keys_present' => array_keys($data),
            ]);
            return redirect($dashboardUrl . '/accounts')
                ->with('error', 'Google did not return a refresh token. Try revoking access at myaccount.google.com/permissions and reconnecting.');
        }

        // Optionally fetch the authorised user's email (helpful audit trail).
        $email = null;
        if (isset($data['access_token'])) {
            try {
                $userInfo = Http::withToken($data['access_token'])
                    ->get('https://www.googleapis.com/oauth2/v2/userinfo');
                if ($userInfo->successful()) {
                    $email = $userInfo->json('email');
                }
            } catch (\Throwable $e) {
                // Email is nice-to-have; don't fail the flow if it errors.
            }
        }

        // Persist on the brand row. The conversion_action_resource and customer_id
        // are seeded separately (we have those values already) — this only updates
        // the OAuth bits.
        ConnectedAccount::updateOrCreate(
            ['brand' => $brand],
            [
                'refresh_token' => $refreshToken,
                'oauth_email' => $email,
                'connected_at' => now(),
            ]
        );

        return redirect($dashboardUrl . '/accounts')
            ->with('success', 'Connected ' . $brand . ' successfully.');
    }
}
