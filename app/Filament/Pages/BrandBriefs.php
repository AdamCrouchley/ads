<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class BrandBriefs extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Brand briefs';
    protected static ?string $title = 'Brand briefs';
    protected static string $view = 'filament.pages.placeholder';
    protected static ?int $navigationSort = 1;

    protected ?string $heading = 'Brand voice documents';
    public string $description = 'Brand briefs anchor every creative-generation prompt — they tell the LLM exactly how each brand sounds, what it says, what it avoids. Three v1 briefs already exist as Word documents (Jimny Rentals NZ, Jimny Rentals AU, Dream Drives). When the analysis layer is built, they will be importable here and editable through a guided wizard.';
    public array $bullets = [
        'Tone, vocabulary, and voice samples per brand',
        'Anti-voice samples (what each brand never sounds like)',
        'Banned word list (validator enforced)',
        'Customer archetype and value prop hierarchy',
        'Compliance notes (NZ Fair Trading Act, trademark)',
    ];
    public string $cta = 'Briefs already exist as v1 Word documents — wizard import comes later';
}
