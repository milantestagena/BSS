<?php

namespace App\Filament\Resources\ReferralPartnerResource\Pages;

use App\Filament\Resources\ReferralPartnerResource;
use Filament\Resources\Pages\ListRecords;

// No CreateAction — resellers are created by promoting an existing user (see
// UserResource's "Make reseller" action), not manually here.
class ListReferralPartners extends ListRecords
{
    protected static string $resource = ReferralPartnerResource::class;
}
