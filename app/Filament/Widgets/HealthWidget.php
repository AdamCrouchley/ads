<?php

namespace App\Filament\Widgets;

use App\Models\ReceivedEvent;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * System health summary.
 *
 * Three signals at a glance:
 *   - When did we last receive an event? (silence > 7 days = warning, > 30 = danger)
 *   - How many events failed processing? (any > 0 = warning)
 *   - Revenue captured this week
 */
class HealthWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $latest = ReceivedEvent::latest('received_at')->first();
        $failedCount = ReceivedEvent::where('processing_status', 'failed')->count();
        $pendingCount = ReceivedEvent::where('processing_status', 'pending')->count();

        // Latest event freshness
        if (!$latest) {
            $lastEventStat = Stat::make('Last event', 'None yet')
                ->description('Awaiting first booking confirmation')
                ->descriptionIcon('heroicon-m-clock')
                ->color('gray');
        } else {
            $hoursAgo = $latest->received_at->diffInHours(now());
            $daysAgo = $latest->received_at->diffInDays(now());

            $color = match (true) {
                $daysAgo > 30 => 'danger',
                $daysAgo > 7 => 'warning',
                default => 'success',
            };

            $lastEventStat = Stat::make('Last event', $latest->received_at->diffForHumans())
                ->description($latest->brand . ' · ' . $latest->booking_reference)
                ->descriptionIcon('heroicon-m-arrow-down-on-square')
                ->color($color);
        }

        // Failed events
        $failedStat = Stat::make('Failed events', $failedCount)
            ->description($failedCount === 0 ? 'All clear' : 'Need attention')
            ->descriptionIcon($failedCount === 0 ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
            ->color($failedCount === 0 ? 'success' : 'danger');

        // Pending uploads
        $pendingStat = Stat::make('Pending upload', $pendingCount)
            ->description($pendingCount === 0 ? 'Nothing queued' : 'Will upload to Google Ads')
            ->descriptionIcon('heroicon-m-arrow-up-tray')
            ->color($pendingCount === 0 ? 'gray' : 'info');

        return [$lastEventStat, $failedStat, $pendingStat];
    }
}
