<?php

namespace App\Filament\Resources\WizardCampaignDestinationPriceResource\Pages;

use App\Filament\Resources\WizardCampaignDestinationPriceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWizardCampaignDestinationPrice extends EditRecord
{
    protected static string $resource = WizardCampaignDestinationPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
