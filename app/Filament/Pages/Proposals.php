<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Proposals extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-light-bulb';
    protected static ?string $navigationGroup = 'Proposals';
    protected static ?string $title = 'Daily proposals';
    protected static string $view = 'filament.pages.placeholder';
    protected static ?int $navigationSort = 1;

    protected ?string $heading = 'AI-generated daily proposals';
    public string $description = 'Each morning at 7am NZT, the analysis layer will review the previous day\'s campaign performance and generate a queue of recommended changes — pause underperforming keywords, scale winning campaigns, draft new ad copy variants, add negative keywords. Each proposal will appear here with reasoning, evidence, and an Approve / Reject action.';
    public array $bullets = [
        'Approval queue with one-tap approve/reject',
        'Reasoning and evidence per proposal',
        'Auto-execute toggle per category (off by default)',
        'Mobile-friendly for approving over coffee',
    ];
    public string $cta = 'Available after the analysis layer is built (Phase 4 of the build)';
}
