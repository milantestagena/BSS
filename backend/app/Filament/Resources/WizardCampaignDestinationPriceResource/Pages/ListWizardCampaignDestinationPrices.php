<?php

namespace App\Filament\Resources\WizardCampaignDestinationPriceResource\Pages;

use App\Filament\Resources\WizardCampaignDestinationPriceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWizardCampaignDestinationPrices extends ListRecords
{
    protected static string $resource = WizardCampaignDestinationPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
