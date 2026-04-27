<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class System extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'System';
    protected static ?string $title = 'System controls';
    protected static string $view = 'filament.pages.placeholder';
    protected static ?int $navigationSort = 1;

    protected ?string $heading = 'Autonomy controls and kill switch';
    public string $description = 'Once the analysis layer is live, this page will host autonomy toggles per category (auto-apply negative keywords, auto-rebalance budgets, etc.) and the master kill switch — one tap to pause all automation immediately. For v1, automation isn\'t running yet, so these controls are placeholders.';
    public array $bullets = [
        'Master kill switch (instant pause of all automation)',
        'Per-category autonomy: auto-execute / approval-required / never',
        'Daily spend ceiling per account (hard cap)',
        'System health monitor (queue depth, last event, error rate)',
        'Audit log (every change applied with reasoning)',
    ];
    public string $cta = 'Activates once Phase 4 (analysis layer) is built';
}
