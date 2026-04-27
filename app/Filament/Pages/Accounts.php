<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Accounts extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationGroup = 'Accounts';
    protected static ?string $title = 'Google Ads & GA4 Accounts';
    protected static string $view = 'filament.pages.placeholder';
    protected static ?int $navigationSort = 1;

    protected ?string $heading = 'Connected accounts';
    public string $description = 'Once the Google Ads API developer token is approved (3-5 business days from application), you will be able to connect each managed account here via OAuth. The conversion uploader reads accounts from this list and pushes pending events to the right Google Ads customer.';
    public array $bullets = [
        'Jimny Rentals NZ — Google Ads + GA4',
        'Dream Drives — Google Ads + GA4',
        'Glovebox — placeholder until ad spend begins',
        'Parked Funds — placeholder until ad spend begins',
        'Free Legs — placeholder until ad spend begins',
    ];
    public string $cta = 'Awaiting API token approval';
}
