<?php

namespace App\Filament\Resources\ReferralPartnerResource\Pages;

use App\Filament\Resources\ReferralPartnerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditReferralPartner extends EditRecord
{
    protected static string $resource = ReferralPartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
