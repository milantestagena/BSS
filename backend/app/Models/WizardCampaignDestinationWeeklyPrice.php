<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One row per (destination price, week) — see the create_wizard_campaign_destination_weekly_
// prices_table migration and WizardCampaignDestinationPrice::estimateAccommodationTotal().
class WizardCampaignDestinationWeeklyPrice extends Model
{
    protected $fillable = [
        'wizard_campaign_destination_price_id',
        'week_start_date',
        'price_per_person_eur',
        // 2026-09-08 — real price for the "quality" tier (8+ guest rating / 4+ star), see the
        // add_quality_tier_price migration's docblock. Null until the owner enters one.
        'quality_tier_price_per_person_eur',
    ];

    protected $casts = [
        'week_start_date' => 'date',
        'price_per_person_eur' => 'float',
        'quality_tier_price_per_person_eur' => 'float',
    ];

    public function destinationPrice(): BelongsTo
    {
        return $this->belongsTo(WizardCampaignDestinationPrice::class, 'wizard_campaign_destination_price_id');
    }
}
