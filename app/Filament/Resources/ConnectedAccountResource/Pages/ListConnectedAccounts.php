<?php

namespace App\Filament\Resources\ConnectedAccountResource\Pages;

use App\Filament\Resources\ConnectedAccountResource;
use Filament\Resources\Pages\ListRecords;

class ListConnectedAccounts extends ListRecords
{
    protected static string $resource = ConnectedAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
