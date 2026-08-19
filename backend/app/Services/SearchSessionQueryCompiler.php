<?php

namespace App\Services;

use App\Models\SearchSession;
use App\Models\TaxonomyNode;
use App\Models\TaxonomyNodeRelation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Compiles a search session's answers into the two shapes the next stage of the pipeline
 * needs — see wizard_architecture memory, 2026-07-30 "SearchSessionQueryCompiler":
 *
 * - toBookingParams(): real Booking.com request parameters (hard filters).
 * - toHonestReportSignals(): soft context for the AI layer that reads Booking's returned
 *   listing descriptions/reviews (vibe tags, persona, climate caveats, distance, cost emphasis).
 *
 * Operates on a session that already has a destination `city_id` chosen — ranking/filtering
 * CANDIDATE cities during the wizard itself is GeographyResolver's job, not this one. Any
 * answer this session hasn't reached yet is simply absent from the output, never an error —
 * a session is rarely fully answered when this might get called for a preview.
 */
class SearchSessionQueryCompiler
{
    /** The four taxonomy types the Big YES/NO picker spans — see AmenityPickerComponent /
     *  applyAmenityYesFilters(). Reused by labelsForSlugs() below. */
    private const AMENITY_TYPES = ['tip_smestaja', 'accommodation_facility', 'room_facility', 'meal_plan'];

    public function __construct(private SearchSession $session)
    {
    }

    public function toBookingParams(): array
    {
        $params = [];

        [$checkin, $checkout] = $this->resolveDates();
        if ($checkin) {
            $params['checkin'] = $checkin->toDateString();
            $params['checkout'] = $checkout->toDateString();
        }

        if ($this->session->adults_count) {
            $params['guests']['number_of_adults'] = $this->session->adults_count;
        }
        if (! empty($this->session->children_ages)) {
            $params['guests']['children'] = $this->session->children_ages;
        }
        if ($this->session->number_of_rooms) {
            $params['guests']['number_of_rooms'] = $this->session->number_of_rooms;
        }

        $destination = $this->destinationNode();
        if ($destination?->bookingLocation) {
            $params['location'] = $destination->bookingLocation->booking_dest_id;
        }

        $budgetTier = $this->session->budgetTier;
        if ($budgetTier) {
            if (isset($budgetTier->meta['min'])) {
                $params['filters']['price']['minimum'] = $budgetTier->meta['min'];
            }
            if (! empty($budgetTier->meta['max'])) {
                $params['filters']['price']['maximum'] = $budgetTier->meta['max'];
            }
        }

        // FK-based single pick — no wizard question drives this today (tip_smestaja has no UI
        // of its own, see wizard_architecture), left in place for whatever eventually does.
        $tipSmestaja = $this->session->tipSmestaja;
        if ($tipSmestaja && ! empty($tipSmestaja->meta['booking_accommodation_type_ids'])) {
            $params['filters']['accommodation_types'] = $tipSmestaja->meta['booking_accommodation_type_ids'];
        }

        $this->applyAmenityYesFilters($params);
        $this->applyFamilyFriendlyFilter($params);
        $this->applyMealPlanPreferenceFilter($params);
        $this->applyMealStyleFilter($params);

        return $params;
    }

    /**
     * A REAL, working public booking.com/searchresults.html URL — no API key, no partner
     * approval, no `Location`/`booking_dest_id` lookup needed. Deliberately does NOT use
     * `dest_id`/`dest_type` (what toBookingParams()['location'] carries): those `Location` rows
     * were seeded as `test_*_city` placeholders back in the pre-swim-campaign city-break era
     * (2026-07-13, "our best guess... NOT yet verified against a real sandbox response") and
     * were never replaced with real Booking dest_ids — using them here would silently produce a
     * broken link. Booking's own site accepts a plain `ss` (destination search string) and runs
     * its own intent parser server-side instead — same as a person typing a city name into the
     * search box, confirmed via developers.booking.com's own search-URL examples, so this needs
     * no dest_id at all. This is the actual outbound/affiliate redirect target once a CJ deep
     * link wrapper goes around it later — not the Partner/Demand API request toBookingParams()
     * feeds today.
     *
     * Null whenever there isn't yet a chosen destination or resolvable dates — same "absent, not
     * an error" convention as the rest of this compiler.
     */
    public function toBookingUrl(): ?string
    {
        $destination = $this->destinationNode();
        if (! $destination) {
            return null;
        }

        [$checkin, $checkout] = $this->resolveDates();
        if (! $checkin) {
            return null;
        }

        $searchTerm = $destination->parent ? "{$destination->label}, {$destination->parent->label}" : $destination->label;

        $params = [
            'ss' => $searchTerm,
            'checkin' => $checkin->toDateString(),
            'checkout' => $checkout->toDateString(),
            'group_adults' => $this->session->adults_count ?: 1,
            'no_rooms' => $this->session->number_of_rooms ?: 1,
            'selected_currency' => 'EUR',
        ];

        $childrenAges = $this->session->children_ages ?? [];
        if (! empty($childrenAges)) {
            $params['group_children'] = count($childrenAges);
        }

        $query = [];
        foreach ($params as $key => $value) {
            $query[] = $key.'='.rawurlencode((string) $value);
        }
        // Booking's site expects one repeated bare `age=` param per child, not PHP's default
        // `age[0]=`/`age[1]=` array-bracket encoding — http_build_query() can't produce that
        // shape, so these are appended by hand instead of folded into $params above.
        foreach ($childrenAges as $age) {
            $query[] = 'age='.rawurlencode((string) $age);
        }

        return 'https://www.booking.com/searchresults.html?'.implode('&', $query);
    }

    /**
     * A REAL, working public flights.booking.com search URL — same "no API key, no partner
     * approval" spirit as toBookingUrl() above, but this scheme isn't publicly documented
     * anywhere (checked, 2026-08-19) so it's built from a real captured example instead: the
     * owner ran an actual search (Niš -> Malta, 2 adults + 3 children) and sent the resulting
     * URL. Deliberately drops that URL's `aid`/`label` params — those read as Booking's own
     * generic/session tracking values from browsing their site directly, not something safe to
     * copy into every link we generate; the real affiliate wrapper goes on once CJ approves,
     * same as toBookingUrl().
     *
     * Owner's own idea, 2026-08-19: flight price is FAR too volatile (yield management,
     * personalized fares, 10x swings days before departure) to estimate ourselves and fold into
     * the budget-fit math — same "false precision" lesson as the reverted budget_shortfall_eur
     * feature, just a worse case of it. This just hands the traveler a live, real search instead
     * of a number we'd get wrong.
     *
     * Destination is the COUNTRY (toCountryCode/toLocationName), not the specific city, matching
     * how the owner's own captured example searched "Malta" rather than a specific airport —
     * flights land at whichever airport serves the region, not literally at the resort town.
     *
     * Origin defaults to Frankfurt (the largest DACH hub) since we don't yet map home_city
     * answers to a specific departure airport — a real known simplification, not an oversight;
     * revisit if/when that mapping gets built.
     */
    public function toBookingFlightsUrl(): ?string
    {
        $destination = $this->destinationNode();
        if (! $destination) {
            return null;
        }

        [$checkin, $checkout] = $this->resolveDates();
        if (! $checkin) {
            return null;
        }

        $country = $destination->type === 'city' ? $destination->parent : $destination;
        if (! $country) {
            return null;
        }

        $params = [
            'type' => 'ROUNDTRIP',
            'adults' => $this->session->adults_count ?: 1,
            'cabinClass' => 'ECONOMY',
            'depart' => $checkin->toDateString(),
            'return' => $checkout->toDateString(),
            'from' => 'FRA.AIRPORT',
            'fromCountry' => 'DE',
            'fromLocationName' => 'Frankfurt Airport',
            'to' => 'Anywhere',
            'toCountryCode' => strtolower((string) ($country->meta['iso_code'] ?? '')),
            'toLocationName' => $country->label,
            'sort' => 'BEST',
            'travelPurpose' => 'leisure',
        ];

        $childrenAges = $this->session->children_ages ?? [];
        if (! empty($childrenAges)) {
            $params['children'] = implode(',', $childrenAges);
        }

        $query = [];
        foreach ($params as $key => $value) {
            $query[] = $key.'='.rawurlencode((string) $value);
        }

        return 'https://flights.booking.com/fly-anywhere/?'.implode('&', $query);
    }

    /**
     * meal_style=kuva_sam ("I'll cook for myself") -> Booking's real `mealplan=999` (Self
     * catering) filter. Split off from applyMealPlanPreferenceFilter, 2026-08-13, alongside
     * meal_style becoming its own mandatory question — the `kuva_sam` taxonomy node itself
     * carries the real booking_meal_plan_id (999), same value the old `samostalno_kuvanje`
     * meal_plan-type node used to carry before it was removed.
     */
    private function applyMealStyleFilter(array &$params): void
    {
        if (($this->session->free_text_answers['meal_style'] ?? null) !== 'kuva_sam') {
            return;
        }

        $meta = TaxonomyNode::where('type', 'meal_style')->where('slug', 'kuva_sam')->value('meta');
        $bookingId = $meta['booking_meal_plan_id'] ?? null;
        if ($bookingId) {
            $params['filters']['meal_plan'] = array_values(array_unique([...($params['filters']['meal_plan'] ?? []), $bookingId]));
        }
    }

    /**
     * The direct meal_plan_preference question (WizardSeeder's broj_putnika step, 2026-08-13,
     * replacing AmenitySuggestionEngine's old budget-ratio guess) -> real Booking mealplan
     * filter. Deliberately a SEPARATE field from amenities_yes (not routed through
     * applyAmenityYesFilters) — two questions writing to the same free_text_answers key would
     * have whichever step persists last silently overwrite the other's picks, see
     * SearchSessionResolver's array_merge docblock.
     */
    private function applyMealPlanPreferenceFilter(array &$params): void
    {
        $slugs = $this->session->free_text_answers['meal_plan_preference'] ?? [];
        if (empty($slugs)) {
            return;
        }

        $ids = TaxonomyNode::where('type', 'meal_plan')
            ->whereIn('slug', $slugs)
            ->pluck('meta')
            ->map(fn (?array $meta) => $meta['booking_meal_plan_id'] ?? null)
            ->filter()
            ->values()
            ->all();

        if (! empty($ids)) {
            $params['filters']['meal_plan'] = array_values(array_unique([...($params['filters']['meal_plan'] ?? []), ...$ids]));
        }
    }

    /**
     * `porodicna_atmosfera` ("Family-friendly atmosphere") -> Booking's real `family_friendly_
     * property=1` filter — owner's find, 2026-08-13, from a manual filter-sidebar export of a
     * live Booking search. Deliberately NOT city/country meta matching (that's what
     * seedFamilyAndQuietTags() covers, for narrowing WHICH destination) — this is the sharper,
     * property-level signal for the actual search once a destination is picked, straight from
     * Booking's own inventory rather than our own editorial judgment about a whole city.
     */
    private function applyFamilyFriendlyFilter(array &$params): void
    {
        if ($this->allPreferenceTagSlugs()->contains('porodicna_atmosfera')) {
            $params['filters']['family_friendly_property'] = 1;
        }
    }

    /**
     * Routes Big-YES amenity picks (free_text_answers.amenities_yes — slugs spanning
     * tip_smestaja/accommodation_facility/room_facility/meal_plan, see wizard_architecture
     * 2026-08-04) to whichever real Booking filter each tag's taxonomy type owns. Merges with
     * (doesn't replace) the FK-based tip_smestaja path above — both can contribute
     * accommodation_types, deduped. Silently skips a tag if it's missing its expected meta key
     * rather than erroring — same "missing data, not a wrong answer" convention as this whole
     * class.
     */
    private function applyAmenityYesFilters(array &$params): void
    {
        $slugs = $this->session->free_text_answers['amenities_yes'] ?? [];
        if (empty($slugs)) {
            return;
        }

        $nodes = TaxonomyNode::whereIn('type', ['tip_smestaja', 'accommodation_facility', 'room_facility', 'meal_plan'])
            ->whereIn('slug', $slugs)
            ->get();

        foreach ($nodes as $node) {
            match ($node->type) {
                'tip_smestaja' => $params['filters']['accommodation_types'] = array_values(array_unique([
                    ...($params['filters']['accommodation_types'] ?? []),
                    ...($node->meta['booking_accommodation_type_ids'] ?? []),
                ])),
                'accommodation_facility' => $params['filters']['accommodation_facilities'][] = $node->meta['booking_facility_id'] ?? null,
                'room_facility' => $params['filters']['room_facilities'][] = $node->meta['booking_facility_id'] ?? null,
                'meal_plan' => $params['filters']['meal_plan'][] = $node->meta['booking_meal_plan_id'] ?? null,
                default => null,
            };
        }

        foreach (['accommodation_facilities', 'room_facilities', 'meal_plan'] as $key) {
            if (isset($params['filters'][$key])) {
                $params['filters'][$key] = array_values(array_filter($params['filters'][$key]));
            }
        }
    }

    public function toHonestReportSignals(): array
    {
        $signals = [];

        $tags = $this->allPreferenceTagSlugs();
        if ($tags->isNotEmpty()) {
            $signals['preference_tags'] = $this->labelsForSlugs(['preference_tag'], $tags);
        }

        if ($this->session->persona) {
            $signals['persona'] = $this->session->persona->label;
        }

        if ($this->session->groupType) {
            $signals['group_type'] = $this->session->groupType->label;
        }

        $relationshipType = trim((string) ($this->session->free_text_answers['relationship_type'] ?? ''));
        if ($relationshipType !== '') {
            $signals['relationship_type'] = $this->labelForSlug('relationship_type', $relationshipType);
        }

        // Index-aligned with bookingParams.guests.children — surfaced here (not forced into a
        // Booking filter) since we don't have a confirmed real ID for the "Cots" room_facility
        // yet (only saw a result count on the public site, no query param — see
        // wizard_architecture 2026-08-03, don't fabricate an ID). Only shown when at least one
        // child actually needs one, so an all-false array doesn't clutter the output.
        if (! empty($this->session->needs_crib) && in_array(true, $this->session->needs_crib, true)) {
            $signals['needs_crib'] = $this->session->needs_crib;
        }

        $climate = $this->climateSignal();
        if ($climate) {
            $signals['climate'] = $climate;
        }

        $distance = $this->session->distanceFromHomeKm();
        if ($distance !== null) {
            $signals['distance_km'] = round($distance);
        }

        $costEmphasis = $this->costEmphasis();
        if ($costEmphasis->isNotEmpty()) {
            $signals['cost_emphasis'] = $costEmphasis->all();
        }

        $budget = $this->budgetSignal();
        if ($budget) {
            $signals['budget'] = $budget;
        }

        $suggestedAmenities = $this->suggestedAmenitiesSignal();
        if ($suggestedAmenities) {
            $signals['suggested_amenities'] = $suggestedAmenities;
        }

        // Big-YES picks (free_text_answers.amenities_yes) — matched ones already drive real
        // Booking filters (applyAmenityYesFilters/toBookingParams), but those only carry opaque
        // Booking IDs, not human-readable slugs, and a tag missing its booking_facility_id gets
        // silently dropped there (see that method's docblock). Surfaced here too, in full, so
        // nothing the user asked for is invisible to whatever reads this signal set.
        $wantedAmenities = $this->session->free_text_answers['amenities_yes'] ?? [];
        if (! empty($wantedAmenities)) {
            $signals['wanted_amenities'] = $this->labelsForSlugs(self::AMENITY_TYPES, $wantedAmenities);
        }

        // The direct meal_plan_preference question (2026-08-13) — same "everything picked must
        // show up here" rule as wanted_amenities, on its own key since it's a separate field.
        $mealPlanPreference = $this->session->free_text_answers['meal_plan_preference'] ?? [];
        if (! empty($mealPlanPreference)) {
            $signals['meal_plan_preference'] = $this->labelsForSlugs(['meal_plan'], $mealPlanPreference);
        }

        // Big-NO picks (free_text_answers.amenities_no) — no real Booking "exclude this
        // facility" filter exists, so unlike Big-YES this only ever surfaces here, for the AI
        // layer to weigh when reading listing descriptions/reviews. See wizard_architecture,
        // 2026-08-04.
        $avoid = $this->session->free_text_answers['amenities_no'] ?? [];
        if (! empty($avoid)) {
            $signals['avoid_amenities'] = $this->labelsForSlugs(self::AMENITY_TYPES, $avoid);
        }

        // Big-YES/Big-NO's unmatched-text fallback (free_text_answers.smestaj_preference /
        // .smestaj_avoid) — typed amenities with no taxonomy match at all (e.g. "hairdryer",
        // not in the seeded vocabulary) used to be captured in the DB but never read by this
        // class, so they silently never reached the compiled output. Bug caught 2026-08-06 by
        // the owner: "sve sto je upisano mora tu, da tutmak koji odlucuje ukapira" — everything
        // written down must show up here, even without a mapped Booking ID.
        $wishlistNotes = trim((string) ($this->session->free_text_answers['smestaj_preference'] ?? ''));
        if ($wishlistNotes !== '') {
            $signals['wishlist_notes'] = array_values(array_filter(array_map('trim', explode("\n", $wishlistNotes))));
        }

        $avoidNotes = trim((string) ($this->session->free_text_answers['smestaj_avoid'] ?? ''));
        if ($avoidNotes !== '') {
            $signals['avoid_notes'] = array_values(array_filter(array_map('trim', explode("\n", $avoidNotes))));
        }

        return $signals;
    }

    /**
     * Shared groundwork for budgetSignal() and suggestedAmenitiesSignal() — both need the same
     * destination country + trip length + BudgetEstimationEngine estimate. Null whenever
     * total_budget, adults_count, a resolvable trip length, or the destination's cost data
     * isn't available yet — same "missing data, not a wrong answer" convention as everything
     * else in this class.
     *
     * `accommodation_total_eur` comes from the DESTINATION node (city, not country — more
     * specific, and every seeded swim city has its own price row) via
     * TaxonomyNode::campaignPriceFor(), 0.0 when the session has no campaign or the price
     * hasn't been filled in yet — see wizard_architecture, 2026-08-05.
     *
     * When that price row is flagged `includes_meals` (e.g. Hurghada/Sharm El Sheikh —
     * Egyptian Red Sea resorts booked almost exclusively all-inclusive/full-board, unlike a
     * bare Spain/Greece apartment), the FOOD estimate is zeroed out — otherwise it would be
     * double-counted on top of a price that already includes meals.
     *
     * @return array{country: TaxonomyNode, estimate: array, accommodation_total_eur: float}|null
     */
    private function resolveBudgetContext(): ?array
    {
        if (! $this->session->total_budget || ! $this->session->adults_count) {
            return null;
        }

        $destination = $this->destinationNode();
        $country = $destination?->type === 'country' ? $destination : $destination?->parent;
        if (! $country) {
            return null;
        }

        [$checkin, $checkout] = $this->resolveDates();
        if (! $checkin) {
            return null;
        }
        $days = $checkin->diffInDays($checkout) + 1;

        $estimate = (new BudgetEstimationEngine)->estimate(
            $country, $this->session->adults_count, count($this->session->children_ages ?? []), $days
        );

        if ($estimate === null) {
            return null;
        }

        $priceRow = $this->session->wizard_campaign_id
            ? $destination?->campaignPriceFor($this->session->wizard_campaign_id)
            : null;

        if ($priceRow?->includes_meals) {
            $estimate = ['eating_out_total_eur' => 0.0, 'self_catering_total_eur' => 0.0];
        }

        $totalTravelers = $this->session->adults_count + count($this->session->children_ages ?? []);
        // Splits nights across whichever campaign weeks they fall in, priced per-week — see
        // WizardCampaignDestinationPrice::estimateAccommodationTotal(), 2026-08-11. Falls back
        // to the old flat price_per_person_eur * days math internally if this destination has
        // no weekly rows yet.
        $accommodationTotal = $priceRow !== null ? $priceRow->estimateAccommodationTotal($checkin, $checkout, $totalTravelers) : 0.0;

        return [
            'country' => $country,
            'estimate' => $estimate,
            'accommodation_total_eur' => $accommodationTotal,
            'meals_included' => (bool) $priceRow?->includes_meals,
        ];
    }

    /**
     * BudgetEstimationEngine's fit assessment for the session's chosen destination's country —
     * see wizard_architecture memory, 2026-07-30 (owner: "korak 7... nema ga Budzet u JSONu").
     * Not a real Booking parameter (Booking has no such filter), so this only ever appears in
     * honestReportSignals.
     */
    private function budgetSignal(): ?array
    {
        $context = $this->resolveBudgetContext();
        if (! $context) {
            return null;
        }

        $children = count($this->session->children_ages ?? []);
        [$checkin, $checkout] = $this->resolveDates();
        $days = $checkin->diffInDays($checkout) + 1;

        // Same threading as GeographyResolver::filterByBudget, 2026-08-14 — every meal_plan_
        // preference pick is checked (not collapsed to one "strongest" slug), and the best one
        // that actually fits wins, e.g. 'sve_ukljuceno' if it fits, else a lighter tier.
        // meal_style threaded too, so 'jede_napolju' never falls back to a self_catering fit.
        $mealPlanSlugs = $this->session->free_text_answers['meal_plan_preference'] ?? [];
        $mealStyle = $this->session->free_text_answers['meal_style'] ?? null;

        return [
            'total_budget_eur' => (float) $this->session->total_budget,
            'fit' => (new BudgetEstimationEngine)->fitFor(
                $context['country'], (float) $this->session->total_budget, $this->session->adults_count, $children, $days,
                $context['accommodation_total_eur'], $context['meals_included'], $mealPlanSlugs, $mealStyle
            ),
            'estimate' => $context['estimate'],
            'accommodation_total_eur' => $context['accommodation_total_eur'],
        ];
    }

    /**
     * AmenitySuggestionEngine's pre-filled (editable, never silently forced) property type /
     * amenity suggestions — see wizard_architecture memory, 2026-08-03. Same "absent until we
     * have enough to compute" convention as budgetSignal(), since it needs the exact same
     * inputs (budget context).
     */
    private function suggestedAmenitiesSignal(): ?array
    {
        $context = $this->resolveBudgetContext();
        if (! $context) {
            return null;
        }

        return (new AmenitySuggestionEngine)->suggest($this->session, $context['estimate'], $context['accommodation_total_eur']);
    }

    /**
     * The [checkin, checkout] Carbon pair to actually use: the session's own explicit dates if
     * set, otherwise a computed recommendation from the termin_category's window (see the
     * "recommended range" decision, wizard_architecture 2026-07-30). Booking always needs real
     * dates to search — this only returns [null, null] if NEITHER exact dates NOR a
     * termin_category window exist yet, i.e. the session genuinely isn't ready to search.
     */
    private function resolveDates(): array
    {
        if ($this->session->date_from && $this->session->date_to) {
            return [Carbon::instance($this->session->date_from), Carbon::instance($this->session->date_to)];
        }

        $termin = $this->terminCategoryNode();
        $windowStart = $termin?->meta['window_start'] ?? null;
        $durationDays = $termin?->meta['default_duration_days'] ?? null;

        if (! $windowStart || ! $durationDays) {
            return [null, null];
        }

        $checkin = $this->nextOccurrenceOf($windowStart);
        $checkout = $checkin->copy()->addDays($durationDays);

        return [$checkin, $checkout];
    }

    /**
     * The next real calendar date matching a "MM-DD" window marker, never in the past — this
     * year's occurrence if it hasn't passed yet, otherwise next year's.
     */
    private function nextOccurrenceOf(string $monthDay): Carbon
    {
        $today = Carbon::today();
        $thisYear = Carbon::createFromFormat('Y-m-d', $today->year.'-'.$monthDay)->startOfDay();

        if ($thisYear->lt($today)) {
            return $thisYear->addYear();
        }

        return $thisYear;
    }

    /**
     * Per-month climate rows for the destination city across the resolved stay, each carrying
     * whatever `honest_report_thresholds` metrics the termin_category defines plus a 'caveats'
     * key when a value falls below a configured threshold — 'mild' between caveat/good, 'cold'
     * below caveat. Purely a surfaced caveat for the Honest Report layer, never a hard exclude
     * (see wizard_architecture backlog — the Hurghada-but-a-bit-cool example).
     */
    private function climateSignal(): ?array
    {
        $destination = $this->destinationNode();
        [$checkin, $checkout] = $this->resolveDates();

        if (! $destination || ! $checkin) {
            return null;
        }

        $months = collect();
        $cursor = $checkin->copy();
        while ($cursor->lte($checkout)) {
            $months->push($cursor->month);
            $cursor->addMonthNoOverflow();
        }
        $months = $months->unique();

        $thresholds = $this->terminCategoryNode()?->meta['honest_report_thresholds'] ?? [];

        $byMonth = $months
            ->map(fn (int $month) => $this->climateRowFor($destination, $month, $thresholds))
            ->filter()
            ->values();

        return $byMonth->isEmpty() ? null : ['by_month' => $byMonth->all()];
    }

    private function climateRowFor(TaxonomyNode $city, int $month, array $thresholds): ?array
    {
        $climate = $city->climateFor($month);
        if (! $climate) {
            return null;
        }

        $row = ['month' => $month];
        $caveats = [];

        foreach ($thresholds as $metric => $bounds) {
            $value = $climate->{$metric} ?? null;
            if ($value === null) {
                continue;
            }

            $row[$metric] = (float) $value;

            if (isset($bounds['good']) && $value < $bounds['good']) {
                $caveats[$metric] = (isset($bounds['caveat']) && $value < $bounds['caveat']) ? 'cold' : 'mild';
            }
        }

        if ($caveats) {
            $row['caveats'] = $caveats;
        }

        return $row;
    }

    /**
     * Resolves one stored slug to its taxonomy node's label — bug fixed 2026-08-06: several
     * honestReportSignals used to surface the raw internal `slug` (e.g. "porodica"), which is
     * an implementation detail, not user/AI-facing text — labels are the properly-translated
     * (English canonical, see CLAUDE.md i18n convention) field meant for that. Falls back to
     * the raw slug if nothing matches, same "missing data, not a wrong answer" convention as
     * the rest of this class, rather than silently dropping the signal.
     */
    private function labelForSlug(string $type, string $slug): string
    {
        return TaxonomyNode::where('type', $type)->where('slug', $slug)->value('label') ?? $slug;
    }

    /**
     * Same as labelForSlug() but for a whole array at once (preference_tags, amenities_yes/no)
     * — one query instead of one per slug.
     */
    private function labelsForSlugs(array $types, iterable $slugs): array
    {
        $slugs = collect($slugs)->filter()->values();
        if ($slugs->isEmpty()) {
            return [];
        }

        $labels = TaxonomyNode::whereIn('type', $types)->whereIn('slug', $slugs)->pluck('label', 'slug');

        return $slugs->map(fn (string $slug) => $labels[$slug] ?? $slug)->values()->all();
    }

    /**
     * The most specific destination node the session has so far: chosen city if picked,
     * otherwise the chosen country/region — "svejedno koji grad" (don't care which city) must
     * not throw away a country the user DID pick. Feeds `location`, climate, and the budget
     * signal — all three were silently going empty once a country was chosen but no specific
     * city was, before this existed (bug caught 2026-07-30 via the debug panel: owner picked
     * Malta, skipped the 3-city choice, and both location AND budget vanished from the output
     * even though country_region was answered).
     */
    private function destinationNode(): ?TaxonomyNode
    {
        return $this->session->city ?? $this->session->countryRegion;
    }

    private function terminCategoryNode(): ?TaxonomyNode
    {
        if (! $this->session->termin_category) {
            return null;
        }

        return TaxonomyNode::where('type', 'termin_category')
            ->where('slug', $this->session->termin_category)
            ->first();
    }

    private function allPreferenceTagSlugs(): Collection
    {
        return collect($this->session->free_text_answers['preference_tags'] ?? [])
            ->merge($this->session->free_text_answers['implied_preference_tags'] ?? [])
            ->unique();
    }

    /**
     * MAX (not SUM) weight per cost_category across every currently-selected taxonomy node.
     * Uses selectedTaxonomyNodeIds() (not selectedTaxonomyNodes(), which is FK fields only) so
     * this also covers preference_tags/persona_tags/termin_category, all stored as bare slugs
     * in free_text_answers rather than FKs — see that method's docblock. Used to duplicate this
     * merge locally (preference_tags only); switched 2026-08-06 to fix the same "Foodie picked
     * via the group persona_group question doesn't weight anything" bug as
     * selectedTaxonomyNodeIds() itself. Queries TaxonomyNodeRelation directly rather than the
     * weightedToward() BelongsToMany's pivot, since Eloquent pivot attributes don't auto-cast
     * jsonb (see TaxonomyNode::seasonalWindowFor()'s docblock for the same gotcha) —
     * TaxonomyNodeRelation itself does cast `meta` to array, so this sidesteps it entirely.
     */
    private function costEmphasis(): Collection
    {
        $nodeIds = $this->session->selectedTaxonomyNodeIds();

        if ($nodeIds->isEmpty()) {
            return collect();
        }

        $rows = TaxonomyNodeRelation::whereIn('from_taxonomy_node_id', $nodeIds)
            ->where('relation_type', 'weighted_toward')
            ->get();

        $categorySlugs = TaxonomyNode::whereIn('id', $rows->pluck('to_taxonomy_node_id'))
            ->pluck('slug', 'id');

        $weights = collect();
        foreach ($rows as $row) {
            $weight = $row->meta['weight'] ?? null;
            $slug = $categorySlugs[$row->to_taxonomy_node_id] ?? null;

            if ($weight === null || $slug === null) {
                continue;
            }

            $weights[$slug] = max($weights[$slug] ?? 0, $weight);
        }

        return $weights;
    }
}
