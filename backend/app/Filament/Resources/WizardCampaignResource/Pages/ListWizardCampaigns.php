<?php

namespace App\Filament\Resources\WizardCampaignResource\Pages;

use App\Filament\Resources\WizardCampaignResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWizardCampaigns extends ListRecords
{
    protected static string $resource = WizardCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
