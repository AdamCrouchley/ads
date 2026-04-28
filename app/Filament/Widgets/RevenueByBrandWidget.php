<?php

namespace App\Filament\Widgets;

use App\Models\ReceivedEvent;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Revenue captured per brand, this month.
 *
 * Lightweight summary so the operator can see which brands are bringing
 * in confirmed bookings without diving into the full events table.
 */
class RevenueByBrandWidget extends BaseWidget
{
    protected static ?int $sort = 3;
    protected int | string | array $columnSpan = 'full';
    protected static ?string $heading = 'Revenue by brand · this month';

    public function getTableRecordKey(\Illuminate\Database\Eloquent\Model $record): string
    {
        return (string) $record->brand;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ReceivedEvent::query()
                    ->selectRaw('brand, COUNT(*) as event_count, SUM(value_amount) as total_value, MAX(received_at) as latest_event')
                    ->where('received_at', '>=', now()->startOfMonth())
                    ->groupBy('brand')
                    ->orderByRaw('SUM(value_amount) DESC')
            )
            ->columns([
                Tables\Columns\TextColumn::make('brand')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'jimny_nz' => 'info',
                        'dream_drives' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('event_count')
                    ->label('Bookings')
                    ->numeric()
                    ->alignRight(),

                Tables\Columns\TextColumn::make('total_value')
                    ->label('Total revenue')
                    ->money('NZD')
                    ->alignRight()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('latest_event')
                    ->label('Last booking')
                    ->dateTime('d M · H:i')
                    ->description(fn ($state) => $state ? \Illuminate\Support\Carbon::parse($state)->diffForHumans() : '—'),
            ])
            ->paginated(false);
    }
}
