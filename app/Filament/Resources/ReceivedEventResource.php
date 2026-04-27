<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReceivedEventResource\Pages;
use App\Models\ReceivedEvent;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The events table — every webhook the receiver has accepted.
 *
 * Read-only by default. The "Reprocess" action becomes meaningful
 * once the conversion uploader exists (Phase 2). For v1, it just
 * resets processing_status to 'pending' so the future uploader will
 * pick it up.
 */
class ReceivedEventResource extends Resource
{
    protected static ?string $model = ReceivedEvent::class;
    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';
    protected static ?string $navigationGroup = 'Events';
    protected static ?string $navigationLabel = 'Received events';
    protected static ?string $modelLabel = 'event';
    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('received_at')
                    ->label('Received')
                    ->dateTime('d M Y · H:i')
                    ->description(fn ($record) => $record->received_at?->diffForHumans())
                    ->sortable(),

                Tables\Columns\TextColumn::make('brand')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'jimny_nz' => 'info',
                        'dream_drives' => 'warning',
                        'parkedfunds', 'glovebox', 'freelegs' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('booking_reference')
                    ->label('Booking')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Booking ref copied')
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('value_amount')
                    ->label('Value')
                    ->money('NZD')
                    ->sortable()
                    ->alignRight(),

                Tables\Columns\IconColumn::make('has_clickid')
                    ->label('Click ID')
                    ->getStateUsing(fn ($record) => !empty($record->gclid) || !empty($record->gbraid) || !empty($record->wbraid))
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-minus-circle')
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
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('event_type')
                    ->label('Type')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('event_id')
                    ->label('Event ID')
                    ->fontFamily('mono')
                    ->limit(20)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('received_at', 'desc')
            ->filters([
                SelectFilter::make('brand')
                    ->options([
                        'jimny_nz' => 'Jimny NZ',
                        'dream_drives' => 'Dream Drives',
                        'parkedfunds' => 'Parked Funds',
                        'glovebox' => 'Glovebox',
                        'freelegs' => 'Free Legs',
                    ])
                    ->multiple(),

                SelectFilter::make('processing_status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'uploaded' => 'Uploaded',
                        'skipped_no_clickid' => 'No click ID',
                        'failed' => 'Failed',
                    ])
                    ->multiple(),

                Filter::make('received_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('From'),
                        Forms\Components\DatePicker::make('until')
                            ->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('received_at', '>=', $d))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('received_at', '<=', $d));
                    }),

                Filter::make('has_clickid')
                    ->label('Has click ID')
                    ->query(fn (Builder $query): Builder => $query->where(function ($q) {
                        $q->whereNotNull('gclid')
                          ->orWhereNotNull('gbraid')
                          ->orWhereNotNull('wbraid');
                    }))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('reprocess')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn ($record) => in_array($record->processing_status, ['failed', 'uploaded']))
                    ->requiresConfirmation()
                    ->modalHeading('Reprocess this event?')
                    ->modalDescription('Resets processing status to pending. The conversion uploader will pick it up on its next run. Use this if a previous upload failed and the underlying issue is now fixed.')
                    ->action(function ($record) {
                        $record->update([
                            'processing_status' => 'pending',
                            'processing_error' => null,
                        ]);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('mark_pending')
                        ->label('Mark as pending (retry)')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $records->each(fn ($record) => $record->update([
                                'processing_status' => 'pending',
                                'processing_error' => null,
                            ]));
                        }),

                    Tables\Actions\ExportBulkAction::make()
                        ->label('Export to CSV'),
                ]),
            ])
            ->emptyStateHeading('No events received yet')
            ->emptyStateDescription('When a booking is confirmed and paid in Glovebox, it will appear here within seconds.')
            ->emptyStateIcon('heroicon-o-inbox');
    }

    public static function infolist(\Filament\Infolists\Infolist $infolist): \Filament\Infolists\Infolist
    {
        return $infolist->schema([
            \Filament\Infolists\Components\Section::make('Event')
                ->columns(2)
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('event_id')->fontFamily('mono')->copyable(),
                    \Filament\Infolists\Components\TextEntry::make('brand')->badge(),
                    \Filament\Infolists\Components\TextEntry::make('event_type'),
                    \Filament\Infolists\Components\TextEntry::make('schema_version')->label('Schema'),
                    \Filament\Infolists\Components\TextEntry::make('received_at')->dateTime('d M Y · H:i:s'),
                    \Filament\Infolists\Components\TextEntry::make('event_occurred_at')->label('Occurred at')->dateTime('d M Y · H:i:s'),
                ]),

            \Filament\Infolists\Components\Section::make('Booking')
                ->columns(2)
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('booking_reference')->fontFamily('mono')->copyable(),
                    \Filament\Infolists\Components\TextEntry::make('value_amount')->money('NZD'),
                    \Filament\Infolists\Components\TextEntry::make('value_currency')->label('Currency'),
                ]),

            \Filament\Infolists\Components\Section::make('Attribution')
                ->columns(2)
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('gclid')->placeholder('—')->fontFamily('mono'),
                    \Filament\Infolists\Components\TextEntry::make('gbraid')->placeholder('—')->fontFamily('mono'),
                    \Filament\Infolists\Components\TextEntry::make('wbraid')->placeholder('—')->fontFamily('mono'),
                ]),

            \Filament\Infolists\Components\Section::make('Processing')
                ->columns(2)
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('processing_status')->badge(),
                    \Filament\Infolists\Components\TextEntry::make('processing_attempts')->label('Attempts'),
                    \Filament\Infolists\Components\TextEntry::make('processed_at')->placeholder('Not yet processed')->dateTime('d M Y · H:i:s'),
                    \Filament\Infolists\Components\TextEntry::make('processing_error')->placeholder('—')->columnSpanFull(),
                ]),

            \Filament\Infolists\Components\Section::make('Raw payload')
                ->collapsed()
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('payload')
                        ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                        ->fontFamily('mono')
                        ->columnSpanFull(),
                ]),

            \Filament\Infolists\Components\Section::make('Source')
                ->columns(2)
                ->collapsed()
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('source_ip')->label('Source IP')->fontFamily('mono'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReceivedEvents::route('/'),
            'view' => Pages\ViewReceivedEvent::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::where('processing_status', 'pending')->count();
        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
