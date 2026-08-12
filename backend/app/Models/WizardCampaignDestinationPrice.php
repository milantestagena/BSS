<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class WizardCampaignDestinationPrice extends Model
{
    protected $fillable = [
        'wizard_campaign_id',
        'taxonomy_node_id',
        'price_per_person_eur',
        'includes_meals',
        'notes',
        'source',
    ];

    protected $casts = [
        'price_per_person_eur' => 'float',
        'includes_meals' => 'boolean',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WizardCampaign::class, 'wizard_campaign_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class, 'taxonomy_node_id');
    }

    public function weeklyPrices(): HasMany
    {
        return $this->hasMany(WizardCampaignDestinationWeeklyPrice::class);
    }

    /**
     * Total accommodation cost for a real stay — splits the nights across whichever
     * campaign weeks they fall in and prices each night at THAT week's rate, instead of one
     * flat per-night price for the whole stay. Owner's ask, 2026-08-11: "3 dana iz ove, 4 iz
     * one nedelje... approx minimum = 3xcenaZaPrvu + 4*cenaZaDrugu". A week with no price
     * entered yet falls back to its nearest priced neighbor (owner's explicit call — a rough
     * estimate beats a silent gap). If this destination has NO weekly rows at all yet (not
     * migrated to weekly pricing, or a future campaign that never needs it), falls back to the
     * old flat `price_per_person_eur` scalar entirely — same behavior as before this feature.
     */
    public function estimateAccommodationTotal(CarbonInterface $checkin, CarbonInterface $checkout, int $totalTravelers): float
    {
        $seasonStart = $this->campaign?->season_start_date;
        $weeklyPrices = $this->weeklyPrices;

        // Owner's catch, 2026-08-12: checkin Sep 19 / checkout Sep 27 is 8 NIGHTS (19-26 slept,
        // checkout morning of the 27th — no night charged for the 27th), not 9. Nights are
        // `diffInDays` with no +1 — the +1 convention belongs to FOOD estimates only (you still
        // eat on checkout day, but you don't sleep there), and had been wrongly copied over here.
        if (! $seasonStart || $weeklyPrices->isEmpty()) {
            return ($this->price_per_person_eur ?? 0.0) * $totalTravelers * $checkin->diffInDays($checkout);
        }

        $pricedWeeks = $weeklyPrices->filter(fn (WizardCampaignDestinationWeeklyPrice $w) => $w->price_per_person_eur !== null);

        $totalPerPerson = 0.0;
        $cursor = $checkin->copy();
        while ($cursor->lt($checkout)) {
            $weekStart = self::weekStartFor($cursor, $seasonStart);
            $totalPerPerson += self::nightlyPriceForWeek($weekStart, $pricedWeeks) ?? 0.0;
            $cursor = $cursor->addDay();
        }

        return $totalPerPerson * $totalTravelers;
    }

    /**
     * Cheapest per-night rate across a date range — used for country-level candidate
     * filtering (GeographyResolver::cheapestAccommodationTotal), where only a representative
     * rate is needed, not a full per-night breakdown. Weekly-aware equivalent of just reading
     * the flat `price_per_person_eur` scalar.
     */
    public function cheapestNightlyRateFor(CarbonInterface $checkin, CarbonInterface $checkout): ?float
    {
        $seasonStart = $this->campaign?->season_start_date;
        $pricedWeeks = $this->weeklyPrices->filter(fn (WizardCampaignDestinationWeeklyPrice $w) => $w->price_per_person_eur !== null);

        if (! $seasonStart || $pricedWeeks->isEmpty()) {
            return $this->price_per_person_eur;
        }

        $rates = collect();
        $cursor = $checkin->copy();
        while ($cursor->lt($checkout)) {
            $weekStart = self::weekStartFor($cursor, $seasonStart);
            $rate = self::nightlyPriceForWeek($weekStart, $pricedWeeks);
            if ($rate !== null) {
                $rates->push($rate);
            }
            $cursor = $cursor->addDay();
        }

        return $rates->isNotEmpty() ? $rates->min() : $this->price_per_person_eur;
    }

    /** The Saturday-aligned week_start_date a given night belongs to, anchored to the
     *  campaign's own season_start_date (always a Saturday, see WizardCampaign::seasonWeeks()).
     *  Dates before the season start clamp to week 0 rather than going negative. */
    public static function weekStartFor(CarbonInterface $date, CarbonInterface $seasonStart): CarbonInterface
    {
        $daysSinceStart = max(0, $seasonStart->diffInDays($date, false));
        $weekIndex = intdiv((int) $daysSinceStart, 7);

        return $seasonStart->copy()->addDays($weekIndex * 7);
    }

    /** Exact week's price if entered, otherwise its nearest (by calendar distance) priced
     *  neighbor — owner's explicit fallback choice, 2026-08-11. Null only when NO week in the
     *  whole set has a price yet. */
    private static function nightlyPriceForWeek(CarbonInterface $weekStart, Collection $pricedWeeks): ?float
    {
        if ($pricedWeeks->isEmpty()) {
            return null;
        }

        $exact = $pricedWeeks->first(fn (WizardCampaignDestinationWeeklyPrice $w) => $w->week_start_date->isSameDay($weekStart));
        if ($exact) {
            return $exact->price_per_person_eur;
        }

        $nearest = $pricedWeeks->sortBy(fn (WizardCampaignDestinationWeeklyPrice $w) => abs($w->week_start_date->diffInDays($weekStart, false)))->first();

        return $nearest?->price_per_person_eur;
    }
}
