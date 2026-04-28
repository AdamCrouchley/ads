<?php

namespace App\Jobs;

use App\Models\ConnectedAccount;
use App\Models\ReceivedEvent;
use App\Services\GoogleAds\GoogleAdsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads pending received_events and uploads them to Google Ads.
 *
 * Runs every 5 minutes via the Laravel scheduler. Single instance enforced
 * by withoutOverlapping() in the schedule definition — no need for explicit
 * locking here.
 *
 * For each brand with a connected account:
 *   1. Pull pending events (status = pending, has click ID, < 60 days old)
 *   2. Batch up to 50 at a time
 *   3. POST to Google Ads uploadClickConversions
 *   4. Mark each event uploaded/failed based on per-row response
 *
 * Failure modes:
 *   - Brand not connected (no refresh token): leave events as pending,
 *     they'll wait until the brand connects via OAuth.
 *   - Whole-batch HTTP failure: log and bail; events stay pending so the
 *     next run retries.
 *   - Per-row failure: mark that specific event failed with the error
 *     message. Won't retry automatically — operator can manually reprocess
 *     from the dashboard once the underlying issue is fixed.
 */
class UploadPendingConversions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * No retries on the job itself — the queue scheduler will run it again
     * in 5 minutes. Retrying immediately on failure rarely helps and can
     * compound rate-limit issues.
     */
    public int $tries = 1;

    public function handle(GoogleAdsClient $client): void
    {
        $batchSize = config('google_ads.upload.batch_size');
        $maxAgeDays = config('google_ads.upload.max_age_days');
        $cutoff = now()->subDays($maxAgeDays);

        $accounts = ConnectedAccount::all();

        foreach ($accounts as $account) {
            if (!$account->isConnected()) {
                continue; // not yet authorised — skip silently
            }

            try {
                $this->uploadForBrand($client, $account, $batchSize, $cutoff);
            } catch (Throwable $e) {
                Log::error('UploadPendingConversions: brand-level failure', [
                    'brand' => $account->brand,
                    'error' => $e->getMessage(),
                ]);

                $account->update([
                    'last_upload_at' => now(),
                    'last_upload_status' => 'error',
                ]);

                // Move on to the next brand — one brand failing shouldn't block others.
            }
        }
    }

    private function uploadForBrand(
        GoogleAdsClient $client,
        ConnectedAccount $account,
        int $batchSize,
        \Carbon\Carbon $cutoff
    ): void {
        $events = ReceivedEvent::query()
            ->where('brand', $account->brand)
            ->where('processing_status', 'pending')
            ->where('received_at', '>=', $cutoff)
            ->where(function ($q) {
                $q->whereNotNull('gclid')
                  ->orWhereNotNull('gbraid')
                  ->orWhereNotNull('wbraid');
            })
            ->orderBy('received_at')
            ->limit($batchSize)
            ->get();

        if ($events->isEmpty()) {
            return; // nothing to do
        }

        $conversions = $events->map(fn ($e) => [
            'gclid' => $e->gclid,
            'gbraid' => $e->gbraid,
            'wbraid' => $e->wbraid,
            'conversion_value' => (float) $e->value_amount,
            'conversion_currency' => $e->value_currency ?? 'NZD',
            // Google wants this format: 'YYYY-MM-DD HH:mm:ss+TZ'
            'conversion_date_time' => $e->event_occurred_at->format('Y-m-d H:i:sP'),
            'order_id' => $e->booking_reference,
        ])->all();

        $results = $client->uploadClickConversions($account, $conversions);

        // Apply per-row results.
        foreach ($events as $idx => $event) {
            $result = $results[$idx] ?? ['success' => false, 'error' => 'No result returned'];

            if ($result['success']) {
                $event->update([
                    'processing_status' => 'uploaded',
                    'processed_at' => now(),
                    'last_upload_attempt_at' => now(),
                    'google_ads_resource_name' => $result['resource_name'] ?? null,
                    'upload_response_summary' => ['gclid_returned' => $result['gclid'] ?? null],
                    'processing_error' => null,
                ]);
            } else {
                $event->update([
                    'processing_status' => 'failed',
                    'last_upload_attempt_at' => now(),
                    'processing_error' => $result['error'] ?? 'Unknown error',
                    'processing_attempts' => ($event->processing_attempts ?? 0) + 1,
                    'upload_response_summary' => ['error' => $result['error'] ?? null],
                ]);
            }
        }

        $account->update([
            'last_upload_at' => now(),
            'last_upload_status' => 'success',
        ]);
    }
}
