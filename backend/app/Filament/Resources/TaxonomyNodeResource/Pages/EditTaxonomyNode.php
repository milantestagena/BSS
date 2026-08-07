<?php

namespace App\Filament\Resources\TaxonomyNodeResource\Pages;

use App\Filament\Resources\TaxonomyNodeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTaxonomyNode extends EditRecord
{
    protected static string $resource = TaxonomyNodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
