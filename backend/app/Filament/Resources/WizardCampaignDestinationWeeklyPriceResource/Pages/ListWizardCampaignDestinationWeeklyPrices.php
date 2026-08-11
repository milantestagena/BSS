<?php

namespace App\Filament\Resources\WizardCampaignDestinationWeeklyPriceResource\Pages;

use App\Filament\Resources\WizardCampaignDestinationWeeklyPriceResource;
use Filament\Resources\Pages\ListRecords;

// No CreateAction — rows only ever come from campaign:seed-weekly-price-rows.
class ListWizardCampaignDestinationWeeklyPrices extends ListRecords
{
    protected static string $resource = WizardCampaignDestinationWeeklyPriceResource::class;
}
