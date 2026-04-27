<?php

namespace App\Filament\Resources\ReceivedEventResource\Pages;

use App\Filament\Resources\ReceivedEventResource;
use Filament\Resources\Pages\ListRecords;

class ListReceivedEvents extends ListRecords
{
    protected static string $resource = ReceivedEventResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
