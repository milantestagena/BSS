<?php

namespace App\Services;

use App\Models\HolidayPricingWindow;
use App\Models\TaxonomyNode;
use Carbon\CarbonImmutable;

/**
 * Estimates an accommodation price for a destination on a given date — see wizard_architecture
 * memory, 2026-08-03. No real per-destination nightly-rate dataset exists publicly (checked:
 * Eurostat's HICP accommodation index is a year-over-year inflation series, not a within-year
 * seasonal profile). Three layers, checked in order:
 *
 *   1. LateSummerAccommodationPrice — a REAL price the owner personally read off Booking.com
 *      for this exact (destination, tier). Whenever present, wins outright — see
 *      wizard_architecture, 2026-08-03 ("otvoricemo entitet za cene, nazvacemo ga late
 *      summer"). This is the only layer that returns a real `price_per_night_eur`.
 *   2. Holiday windows (global, not per-country) — a hard spike that dominates whatever base
 *      season it lands in, used to pick the TIER when no real price exists yet. Owner's own
 *      example: Malta at New Year's is nowhere close to its normal (even off-season) rate.
 *   3. The destination's own month -> season_tier row (TaxonomyNodeAccommodationSeason),
 *      parent-fallback city -> country like climateFor()/culturalTierFor().
 *
 * Multipliers (layer 2/3) are plain tunable constants, not measured values — v1 judgment
 * calls the owner can correct once real signal comes in. They exist only to rank/compare
 * destinations that have no real price yet; once a real price lands for a tier, that tier's
 * multiplier is simply unused.
 */
class AccommodationPriceEstimator
{
    private const SEASON_MULTIPLIERS = [
        'van_sezone' => 1.0,
        'pred_post_sezona' => 1.4,
        'sezona' => 2.0,
    ];

    /**
     * @return array{tier: string, multiplier: ?float, price_per_night_eur: ?float, is_holiday: bool, holiday_key: ?string, source: string}|null
     *         Null only if the destination has no season/holiday data at all (not even via a
     *         parent) — there's nothing to even pick a tier from.
     */
    public function estimateFor(TaxonomyNode $destination, \DateTimeInterface $date): ?array
    {
        $date = CarbonImmutable::instance($date);

        $holiday = $this->matchingHoliday($destination, $date);
        $season = $holiday === null ? $destination->accommodationSeasonTierFor($date->month) : null;
        $tier = $holiday !== null ? 'praznici' : $season?->season_tier;

        if ($tier === null) {
            return null;
        }

        $real = $destination->lateSummerPriceFor($tier);
        if ($real !== null) {
            return [
                'tier' => $tier,
                'multiplier' => null,
                'price_per_night_eur' => $real->price_per_night_eur,
                'is_holiday' => $tier === 'praznici',
                'holiday_key' => $holiday?->key,
                'source' => $real->source,
            ];
        }

        return [
            'tier' => $tier,
            'multiplier' => $holiday !== null ? $holiday->price_multiplier : self::SEASON_MULTIPLIERS[$tier],
            'price_per_night_eur' => null,
            'is_holiday' => $tier === 'praznici',
            'holiday_key' => $holiday?->key,
            'source' => $holiday !== null ? $holiday->source : $season->source,
        ];
    }

    /**
     * Checks the (target year - 1, target year, target year + 1) instances of every holiday
     * window rather than just the target year — a Dec 24 + 9-day window for christmas_newyear
     * spills into January of the next year, so a query date of Jan 1st must also be checked
     * against DECEMBER of the previous year's window, not just its own year's.
     */
    private function matchingHoliday(TaxonomyNode $destination, CarbonImmutable $date): ?HolidayPricingWindow
    {
        $windows = HolidayPricingWindow::all();
        $easterCalendar = $this->easterCalendarFor($destination);

        $matches = [];
        foreach ($windows as $window) {
            foreach ([$date->year - 1, $date->year, $date->year + 1] as $year) {
                $anchor = $window->is_easter_based
                    ? $this->easterDate($year, $easterCalendar)
                    : CarbonImmutable::create($year, $window->month, $window->day);

                $start = $anchor->subDays($window->window_before_days)->startOfDay();
                $end = $anchor->addDays($window->window_after_days)->endOfDay();

                if ($date->betweenIncluded($start, $end)) {
                    $matches[] = $window;
                }
            }
        }

        if (empty($matches)) {
            return null;
        }

        return collect($matches)->sortByDesc('price_multiplier')->first();
    }

    /**
     * 'western' (default) or 'orthodox' — a property of the destination country, not of the
     * holiday window itself. Greece and Cyprus (2 of the 10 seeded swim countries) are
     * Orthodox-majority; everything else defaults to western. Walks the parent chain the same
     * way climateFor()/culturalTierFor() do, since a city node won't usually carry this itself.
     */
    private function easterCalendarFor(TaxonomyNode $destination): string
    {
        $node = $destination;
        while ($node !== null) {
            if (isset($node->meta['easter_calendar'])) {
                return $node->meta['easter_calendar'];
            }
            $node = $node->parent;
        }

        return 'western';
    }

    private function easterDate(int $year, string $calendar): CarbonImmutable
    {
        return $calendar === 'orthodox'
            ? $this->orthodoxEasterDate($year)
            : CarbonImmutable::instance(new \DateTimeImmutable(\date('Y-m-d', \easter_date($year))));
    }

    /**
     * Meeus' algorithm for the Julian-calendar Orthodox Easter date, converted to the Gregorian
     * calendar via the standard +13 day offset — valid for years 1900-2099. A deterministic
     * calendrical calculation, not a fabricated number.
     */
    private function orthodoxEasterDate(int $year): CarbonImmutable
    {
        $a = $year % 4;
        $b = $year % 7;
        $c = $year % 19;
        $d = (19 * $c + 15) % 30;
        $e = (2 * $a + 4 * $b - $d + 34) % 7;
        $month = intdiv($d + $e + 114, 31);
        $day = (($d + $e + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day)->addDays(13);
    }
}
