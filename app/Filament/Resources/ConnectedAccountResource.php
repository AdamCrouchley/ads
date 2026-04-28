<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ConnectedAccountResource\Pages;
use App\Models\ConnectedAccount;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Connected accounts admin — replaces the previous placeholder Accounts page.
 *
 * Read-mostly: the rows are seeded with brand + customer_id + conversion action,
 * and the OAuth flow populates the auth columns. The operator's main interaction
 * is clicking "Connect" / "Re-authorise" buttons.
 */
class ConnectedAccountResource extends Resource
{
    protected static ?string $slug = 'accounts';

    protected static ?string $model = ConnectedAccount::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationGroup = 'Accounts';
    protected static ?string $navigationLabel = 'Connected accounts';
    protected static ?string $modelLabel = 'connected account';
    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_name')
                    ->label('Brand')
                    ->description(fn ($record) => $record->brand)
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('customer_id')
                    ->label('Customer ID')
                    ->fontFamily('mono')
                    ->copyable(),

                Tables\Columns\IconColumn::make('is_connected')
                    ->label('OAuth')
                    ->getStateUsing(fn ($record) => $record->isConnected())
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('warning'),

                Tables\Columns\TextColumn::make('oauth_email')
                    ->label('Authorised by')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('connected_at')
                    ->label('Connected')
                    ->dateTime('d M Y · H:i')
                    ->placeholder('Not connected')
                    ->description(fn ($record) => $record->connected_at?->diffForHumans())
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_upload_at')
                    ->label('Last upload')
                    ->dateTime('d M · H:i')
                    ->placeholder('—')
                    ->description(fn ($record) => $record->last_upload_at?->diffForHumans()),

                Tables\Columns\TextColumn::make('last_upload_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'success' => 'success',
                        'error' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('connect')
                    ->label(fn ($record) => $record->isConnected() ? 'Reconnect' : 'Connect')
                    ->icon('heroicon-o-link')
                    ->color(fn ($record) => $record->isConnected() ? 'gray' : 'primary')
                    ->url(fn ($record) => route('google-ads.connect', ['brand' => $record->brand]))
                    ->openUrlInNewTab(false),

                Tables\Actions\Action::make('disconnect')
                    ->label('Disconnect')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn ($record) => $record->isConnected())
                    ->requiresConfirmation()
                    ->modalHeading('Disconnect this brand?')
                    ->modalDescription('Removes the stored refresh token. Pending conversions for this brand will not upload until you reconnect. The customer ID and conversion action are preserved.')
                    ->action(function ($record) {
                        $record->update([
                            'refresh_token' => null,
                            'oauth_email' => null,
                            'connected_at' => null,
                        ]);
                    }),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConnectedAccounts::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // rows are seeded; no UI for creation
    }
}
