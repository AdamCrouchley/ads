<?php

namespace App\Filament\Widgets;

use App\Models\ReceivedEvent;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class EventStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $today = ReceivedEvent::whereDate('received_at', today())->count();
        $thisWeek = ReceivedEvent::where('received_at', '>=', now()->startOfWeek())->count();
        $thisMonth = ReceivedEvent::where('received_at', '>=', now()->startOfMonth())->count();

        $revenueThisMonth = ReceivedEvent::where('received_at', '>=', now()->startOfMonth())
            ->sum('value_amount');

        $totalEvents = ReceivedEvent::count();
        $eventsWithClickId = ReceivedEvent::where(function ($q) {
            $q->whereNotNull('gclid')
              ->orWhereNotNull('gbraid')
              ->orWhereNotNull('wbraid');
        })->count();

        $attributionRate = $totalEvents > 0
            ? round(($eventsWithClickId / $totalEvents) * 100, 1)
            : 0;

        $weekChart = $this->buildWeekChart();

        return [
            Stat::make('Events today', $today)
                ->description($thisWeek . ' this week · ' . $thisMonth . ' this month')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart($weekChart)
                ->color('info'),

            Stat::make('Revenue this month', '$' . number_format((float) $revenueThisMonth, 0) . ' NZD')
                ->description('Confirmed booking value')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Attribution rate', $attributionRate . '%')
                ->description($eventsWithClickId . ' of ' . $totalEvents . ' events have a click ID')
                ->descriptionIcon('heroicon-m-cursor-arrow-rays')
                ->color($attributionRate >= 50 ? 'success' : ($attributionRate >= 20 ? 'warning' : 'gray')),
        ];
    }

    private function buildWeekChart(): array
    {
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->startOfDay();
            $count = ReceivedEvent::whereDate('received_at', $date)->count();
            $days[] = $count;
        }
        return $days;
    }
}
