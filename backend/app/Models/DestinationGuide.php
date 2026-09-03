<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use App\Services\BudgetEstimationEngine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

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
 *  - Vibe/character flavor text  -> $node->meta['vibe_profile']['description']
 *  - Accommodation cost          -> accommodationPriceEur()/accommodationPriceRangeEur() below
 *  - Food cost, BOTH styles      -> foodCostEatingOutPerAdultPerDayEur()/
 *                                    foodCostSelfCateringPerAdultPerDayEur() below — shown
 *                                    regardless of the session's own meal_style pick (owner's
 *                                    call, 2026-08-19: "sto pa da nema dodatni info, mozda se
 *                                    predomisli" — a browsing visitor hasn't committed yet).
 *
 * GENUINELY NEW research, every pass:
 *  1. itinerary (country-level only) — 3-6 stops {location, nights, highlight}. Only
 *     itinerary-ize cities that actually have a real campaign price on file.
 *  2. accommodation_cost_notes — qualitative only (e.g. "book a private-bath room, check
 *     reviews"). NEVER hardcode a € figure here — all real numbers above are read live.
 *  3. extra_tips — 2-4 bullets NOT already covered by the composed section above.
 *  4. images — 4-6 real Unsplash/Pexels CDN URLs + attribution. NEVER Booking.com's own
 *     images — same "automated means"/ToS risk already rejected for auto-pulling prices.
 *  Always set researched_at + source when writing or refreshing a row.
 */
class DestinationGuide extends Model
{
    use HasTranslations;

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

    /**
     * Canonical-string stand-ins so HasTranslations::translate() (which hashes/stores against
     * `(string) $this->{$field}`) has something to hash for these two JSON-array columns —
     * same reason TaxonomyNode needed vibe_profile_description as a real accessor instead of
     * translating a path inside raw `meta`. `itinerary_highlights` only carries the `highlight`
     * strings (not `location`/`nights`, which never need translating) so staleness only fires
     * when the highlight text itself actually changes.
     */
    public function getExtraTipsJoinedAttribute(): string
    {
        return json_encode($this->getAttribute('extra_tips') ?? []);
    }

    public function getItineraryHighlightsAttribute(): string
    {
        return json_encode(array_column($this->getAttribute('itinerary') ?? [], 'highlight'));
    }

    /**
     * GraphQL-facing resolver for `extraTips` — @translate can't be used directly here (it
     * only ever wraps one resolved scalar and would hand a raw JSON string back as a
     * `[String!]` list). Locale comes from the same X-Locale header TranslateDirective reads;
     * falls back to the canonical English array when no 'de' translation exists yet.
     */
    public function extraTips(): array
    {
        $locale = app(Request::class)->header('X-Locale', 'en');
        $stored = $this->translate('extra_tips_joined', $locale);

        return $stored !== null ? json_decode($stored, true) : ($this->getAttribute('extra_tips') ?? []);
    }

    /**
     * GraphQL-facing resolver for `itinerary` — same reasoning as extraTips() above. Only
     * `highlight` is translated per stop; `location`/`nights` pass through untouched.
     */
    public function itinerary(): ?array
    {
        $stops = $this->getAttribute('itinerary');
        if (! $stops) {
            return null;
        }

        $locale = app(Request::class)->header('X-Locale', 'en');
        $stored = $this->translate('itinerary_highlights', $locale);
        $translatedHighlights = $stored !== null ? json_decode($stored, true) : null;

        return collect($stops)->map(function (array $stop, int $i) use ($translatedHighlights) {
            if ($translatedHighlights && array_key_exists($i, $translatedHighlights) && $translatedHighlights[$i] !== null) {
                $stop['highlight'] = $translatedHighlights[$i];
            }

            return $stop;
        })->all();
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

    /** Per-adult, per-day estimate if eating at restaurants — live off BudgetEstimationEngine,
     *  same math the wizard's own budget-fit narrowing already uses. Shown alongside the
     *  self-catering figure regardless of the session's own meal_style pick — see checklist. */
    public function foodCostEatingOutPerAdultPerDayEur(): ?float
    {
        return (new BudgetEstimationEngine)->perAdultDailyEatingOutEur($this->hospitalityContext());
    }

    public function foodCostSelfCateringPerAdultPerDayEur(): ?float
    {
        return (new BudgetEstimationEngine)->perAdultDailySelfCateringEur($this->hospitalityContext());
    }

    /** hospitality meta is seeded at COUNTRY level only (see WizardSeeder::seedSwimCountryProfiles)
     *  — a city-level guide reads its parent's, same parent-fallback spirit as
     *  TaxonomyNode::campaignPriceFor/culturalTierFor elsewhere in this codebase. */
    private function hospitalityContext(): TaxonomyNode
    {
        return $this->destination->type === 'city' ? $this->destination->parent : $this->destination;
    }
}
