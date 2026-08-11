<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One row per (destination price, week) — see the create_wizard_campaign_destination_weekly_
// prices_table migration and WizardCampaignDestinationPrice::estimateAccommodationTotal().
class WizardCampaignDestinationWeeklyPrice extends Model
{
    protected $fillable = ['wizard_campaign_destination_price_id', 'week_start_date', 'price_per_person_eur'];

    protected $casts = [
        'week_start_date' => 'date',
        'price_per_person_eur' => 'float',
    ];

    public function destinationPrice(): BelongsTo
    {
        return $this->belongsTo(WizardCampaignDestinationPrice::class, 'wizard_campaign_destination_price_id');
    }
}
