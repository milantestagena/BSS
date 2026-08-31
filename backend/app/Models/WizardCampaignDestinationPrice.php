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

    /**
     * `price_per_person_eur` is really "price of a 2-person apartment, per night" — the base
     * rate `x` a 1 or 2-person booking pays as a whole unit, NOT a literal per-head rate
     * (renamed in meaning, not in column, 2026-08-31 — see roomMultiplierSumFor's docblock for
     * why). These are the real occupancy-scaling multipliers on top of that base, derived from
     * owner-researched comparison prices across Alanya/Rethymno (compounding ~20% per person
     * above 2 — 1.2, 1.2², 1.2³ — matched real captured prices within a few percent at both).
     */
    private const APARTMENT_MULTIPLIERS = [2 => 1.0, 3 => 1.2, 4 => 1.44, 5 => 1.728];

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
     *
     * The root fix for the Rhodes bug (2026-08 session): accommodation was priced as a straight
     * linear `price_per_person_eur * $totalTravelers`, which silently divided a solo-studio
     * price across a whole family. `$totalTravelers` is now translated into a sum of real
     * apartment-occupancy multipliers via roomMultiplierSumFor() instead of used directly — see
     * that method's docblock for the full occupancy model.
     */
    public function estimateAccommodationTotal(CarbonInterface $checkin, CarbonInterface $checkout, int $totalTravelers, bool $sameUnit = false): float
    {
        $roomMultiplierSum = self::roomMultiplierSumFor($totalTravelers, $sameUnit);
        $seasonStart = $this->campaign?->season_start_date;
        // Bug fixed 2026-08-14: this used to check `$this->weeklyPrices->isEmpty()` — whether
        // any weekly ROWS exist at all — not whether any of them actually have a price. Weekly
        // rows are pre-created empty for every destination (see campaign:seed-destination-
        // weekly-price-rows) well before they're filled in, so a destination with a real flat
        // `price_per_person_eur` but still-empty weekly rows silently computed a total of 0.0
        // instead of falling back to that flat price — starved every such city of a real
        // accommodation total (no price_rank color, budget fit always "insufficient", zero
        // Honest Report accommodation cost). `cheapestNightlyRateFor` below already had the
        // correct check; this now matches it.
        $pricedWeeks = $this->weeklyPrices->filter(fn (WizardCampaignDestinationWeeklyPrice $w) => $w->price_per_person_eur !== null);

        // Owner's catch, 2026-08-12: checkin Sep 19 / checkout Sep 27 is 8 NIGHTS (19-26 slept,
        // checkout morning of the 27th — no night charged for the 27th), not 9. Nights are
        // `diffInDays` with no +1 — the +1 convention belongs to FOOD estimates only (you still
        // eat on checkout day, but you don't sleep there), and had been wrongly copied over here.
        if (! $seasonStart || $pricedWeeks->isEmpty()) {
            return ($this->price_per_person_eur ?? 0.0) * $roomMultiplierSum * $checkin->diffInDays($checkout);
        }

        $totalPerNight = 0.0;
        $cursor = $checkin->copy();
        while ($cursor->lt($checkout)) {
            $weekStart = self::weekStartFor($cursor, $seasonStart);
            $totalPerNight += self::nightlyPriceForWeek($weekStart, $pricedWeeks) ?? 0.0;
            $cursor = $cursor->addDay();
        }

        return $totalPerNight * $roomMultiplierSum;
    }

    /**
     * Translates a headcount into a sum of real apartment-occupancy multipliers, to multiply
     * against the base 2-person nightly rate — replaces the old straight `* $totalTravelers`.
     *
     * - 1-3 travelers: always exactly 1 apartment, sized max(travelers, 2) — a solo traveler
     *   still books (and pays for) a 2-person unit, there's no cheaper "1-person" product.
     * - 4-5 travelers with $sameUnit: 1 apartment sized to the headcount (real captured prices
     *   for both sizes — Alanya 75/85€, Rethymno 100/120€ per night, both close to the
     *   compounding curve). $sameUnit only ever applies at exactly 4 or 5 — see
     *   WizardCampaignDestinationPriceTest and the frontend's showRoomsTogetherQuestion, which
     *   only asks the "stay together?" question for those two sizes.
     * - Everything else (6+, or 4-5 without $sameUnit): split across multiple 2-3 person
     *   apartments via roomSizesFor() and sum each room's multiplier. No extra margin on top —
     *   owner's explicit call, 2026-08-31 ("moje [1.1] je bilo gruba procena", dropped in favor
     *   of summing the real per-room multipliers precisely).
     */
    public static function roomMultiplierSumFor(int $totalTravelers, bool $sameUnit = false): float
    {
        if ($totalTravelers <= 0) {
            return 0.0;
        }

        if ($totalTravelers <= 3) {
            return self::APARTMENT_MULTIPLIERS[max(2, $totalTravelers)];
        }

        if ($sameUnit && ($totalTravelers === 4 || $totalTravelers === 5)) {
            return self::APARTMENT_MULTIPLIERS[$totalTravelers];
        }

        return collect(self::roomSizesFor($totalTravelers))
            ->sum(fn (int $size) => self::APARTMENT_MULTIPLIERS[$size]);
    }

    /** True only when a "stay together?" answer is actually meaningful — exactly 4 or 5
     *  travelers, and the session answered "yes" (number_of_rooms === 1). Anything else (≤3, 6+,
     *  or 4-5 answered "no"/unanswered) splits across rooms regardless of this value — see
     *  roomMultiplierSumFor(). Centralized here so the 4 call sites (GeographyResolver ×3,
     *  SearchSessionQueryCompiler ×1) can't drift on the "4 or 5 only" condition. */
    public static function wantsSameUnit(int $totalTravelers, ?int $numberOfRooms): bool
    {
        return ($totalTravelers === 4 || $totalTravelers === 5) && $numberOfRooms === 1;
    }

    /**
     * Bin-packs a headcount into apartments of size 2 or 3, preferring 3s (cheaper per person
     * per the multiplier table — two 3-person apartments cost 2.4x total vs three 2-person
     * apartments at 3x for the same 6 people) but never leaving a lone room of 1 (a solo
     * leftover still needs a full 2-person-priced unit, so it's cheaper to trade one 3 for two
     * 2s instead). Owner-verified against real examples: 6→[3,3], 7→[3,2,2], 8→[3,3,2].
     */
    private static function roomSizesFor(int $totalTravelers): array
    {
        $threes = intdiv($totalTravelers, 3);
        $remainder = $totalTravelers % 3;

        if ($remainder === 0) {
            return array_fill(0, $threes, 3);
        }

        if ($remainder === 1) {
            return array_merge(array_fill(0, max(0, $threes - 1), 3), [2, 2]);
        }

        return array_merge(array_fill(0, $threes, 3), [2]);
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
