<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ReceivedEventResource;
use App\Models\ReceivedEvent;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestEventsWidget extends BaseWidget
{
    protected static ?int $sort = 4;
    protected int | string | array $columnSpan = 'full';
    protected static ?string $heading = 'Latest events';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ReceivedEvent::query()
                    ->latest('received_at')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('received_at')
                    ->label('Received')
                    ->dateTime('H:i')
                    ->description(fn ($record) => $record->received_at?->diffForHumans()),

                Tables\Columns\TextColumn::make('brand')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'jimny_nz' => 'info',
                        'dream_drives' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('booking_reference')
                    ->label('Booking')
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('value_amount')
                    ->label('Value')
                    ->money('NZD')
                    ->alignRight(),

                Tables\Columns\IconColumn::make('has_clickid')
                    ->label('Click ID')
                    ->getStateUsing(fn ($record) => !empty($record->gclid) || !empty($record->gbraid) || !empty($record->wbraid))
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('processing_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'uploaded' => 'success',
                        'pending' => 'info',
                        'skipped_no_clickid' => 'gray',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'uploaded' => 'Uploaded',
                        'pending' => 'Pending',
                        'skipped_no_clickid' => 'No click ID',
                        'failed' => 'Failed',
                        default => $state,
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => ReceivedEventResource::getUrl('view', ['record' => $record])),
            ])
            ->paginated(false)
            ->emptyStateHeading('No events yet')
            ->emptyStateDescription('Webhook events from confirmed bookings will appear here.');
    }
}
