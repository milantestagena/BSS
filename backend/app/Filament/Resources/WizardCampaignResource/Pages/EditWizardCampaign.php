<?php

namespace App\Filament\Resources\WizardCampaignResource\Pages;

use App\Filament\Resources\WizardCampaignResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWizardCampaign extends EditRecord
{
    protected static string $resource = WizardCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
