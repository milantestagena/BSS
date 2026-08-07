<?php

namespace App\Filament\Resources\TaxonomyNodeResource\Pages;

use App\Filament\Resources\TaxonomyNodeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTaxonomyNodes extends ListRecords
{
    protected static string $resource = TaxonomyNodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
