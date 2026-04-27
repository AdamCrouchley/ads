<?php

/**
 * Receiver-side webhook config.
 *
 * Each brand has a shared secret that the firing Laravel app uses to
 * sign requests, and we use to verify them. Secrets must be different
 * per brand — a leak in one app should not let an attacker forge
 * webhooks for another brand.
 *
 * Generate each secret with: `php -r "echo bin2hex(random_bytes(32));"`
 * Each secret is a 64-char hex string.
 *
 * Schema versions: only listed versions are accepted. Bump when the
 * payload contract changes.
 */

return [
    'brands' => [
        'jimny_nz'     => ['secret' => env('WEBHOOK_SECRET_JIMNY_NZ')],
        'dream_drives' => ['secret' => env('WEBHOOK_SECRET_DREAM_DRIVES')],
        'parkedfunds'  => ['secret' => env('WEBHOOK_SECRET_PARKEDFUNDS')],
        'glovebox'     => ['secret' => env('WEBHOOK_SECRET_GLOVEBOX')],
        'freelegs'     => ['secret' => env('WEBHOOK_SECRET_FREELEGS')],
    ],

    'accepted_schema_versions' => ['1'],

    'accepted_event_types' => [
        'booking.confirmed',
        'booking.confirmed.test',
        // Future: 'subscription.activated', 'subscription.upgraded', etc.
    ],

    // Reject events older than this many hours. Defends against replay
    // attacks where an attacker captures a valid signed request and
    // replays it later. 24h is generous — Rentops retries cap at 24h
    // total elapsed time for a real event.
    'max_event_age_hours' => 24,
];
