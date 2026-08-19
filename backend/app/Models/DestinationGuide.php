<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Optional "deep-dive" destination content — itinerary/costs/tips/photos, one row per
 * (campaign, destination), see the destination_guides migration docblock for the full
 * reasoning (place+CAMPAIGN scoped, static/researched-and-stored, never a hardcoded price).
 *
 * REFRESH CHECKLIST — follow this every time a guide is written or refreshed:
 *
 * Compose from EXISTING data (no fresh research needed):
 *  - Climate/weather flavor      -> TaxonomyNode::climateFor()/climateMonths()
 *  - Water/dress-code/halal/etc  -> TaxonomyNode::culturalTierFor('tap_water'|'dress_code'|...)
 *                                    (tier >= 3 = genuinely tip-worthy, don't surface tier 1-2)
 *  - Cost color (meal/beer/etc.) -> $node->meta['hospitality'] / $node->meta['local_stores']
 *  - Vibe/character flavor text  -> $node->meta['vibe_profile']['description']
 *
 * GENUINELY NEW research, every pass:
 *  1. itinerary (country-level only) — 3-6 stops {location, nights, highlight}. Only
 *     itinerary-ize cities that actually have a real campaign price on file.
 *  2. accommodation_cost_notes — qualitative only. NEVER hardcode a € figure here — the real
 *     number is read live via accommodationPriceEur()/accommodationPriceRangeEur() below.
 *  3. extra_tips — 2-4 bullets NOT already covered by the composed section above.
 *  4. images — 4-6 real Unsplash/Pexels CDN URLs + attribution. NEVER Booking.com's own
 *     images — same "automated means"/ToS risk already rejected for auto-pulling prices.
 *  Always set researched_at + source when writing or refreshing a row.
 */
class DestinationGuide extends Model
{
    protected $fillable = [
        'wizard_campaign_id',
        'taxonomy_node_id',
        'itinerary',
        'accommodation_cost_notes',
        'extra_tips',
        'images',
        'researched_at',
        'source',
    ];

    protected $casts = [
        'itinerary' => 'array',
        'extra_tips' => 'array',
        'images' => 'array',
        'researched_at' => 'date',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WizardCampaign::class, 'wizard_campaign_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class, 'taxonomy_node_id');
    }

    /** City-level real price, read LIVE (never stored) — null for country-level guides. */
    public function accommodationPriceEur(): ?float
    {
        if ($this->destination->type !== 'city') {
            return null;
        }

        return $this->destination->campaignPriceFor($this->wizard_campaign_id)?->price_per_person_eur;
    }

    /**
     * Country-level real price RANGE, read LIVE across the country's priced child cities in
     * this campaign — null for city-level guides. Countries don't get their own price row
     * today (campaign:seed-destination-price-rows only creates rows for type=city), so this
     * aggregates from children rather than reading a single stored number, same spirit as
     * GeographyResolver::averageAccommodationTotal's child-aggregation.
     */
    public function accommodationPriceRangeEur(): ?array
    {
        if ($this->destination->type !== 'country') {
            return null;
        }

        $prices = $this->destination->children()
            ->with(['campaignDestinationPrices' => fn ($q) => $q->where('wizard_campaign_id', $this->wizard_campaign_id)])
            ->get()
            ->pluck('campaignDestinationPrices')
            ->flatten()
            ->pluck('price_per_person_eur')
            ->filter();

        return $prices->isEmpty() ? null : ['min' => $prices->min(), 'max' => $prices->max()];
    }
}
