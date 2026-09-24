<?php

namespace App\Services;

use App\Models\SearchSession;
use App\Models\TaxonomyNode;
use App\Models\TaxonomyNodeRelation;
use App\Models\WizardCampaignDestinationPrice;
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
    /** The taxonomy types the Big YES/NO picker spans — see AmenityPickerComponent /
     *  applyAmenityYesFilters(). Reused by labelsForSlugs() below, and (public since 2026-08-24)
     *  by FreeTextAmenityResolver's catalog lookup — same vocabulary, one source of truth. */
    public const AMENITY_TYPES = ['tip_smestaja', 'accommodation_facility', 'room_facility', 'meal_plan', 'stay_type', 'popular_activity'];

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

        // FK-based single pick — dormant, no wizard question ever wrote to `tip_smestaja_id`
        // (see wizard_architecture) — left in place in case that ever changes. Merges with (does
        // not replace) applyAccommodationTypePreferenceFilter() below, same "both can contribute,
        // deduped" pattern as everywhere else multiple sources feed one Booking filter.
        $tipSmestaja = $this->session->tipSmestaja;
        if ($tipSmestaja && ! empty($tipSmestaja->meta['booking_accommodation_type_ids'])) {
            $params['filters']['accommodation_types'] = $tipSmestaja->meta['booking_accommodation_type_ids'];
        }

        $this->applyAmenityYesFilters($params);
        $this->applyFamilyFriendlyFilter($params);
        $this->applyMealStyleFilter($params);
        $this->applyAccommodationTypePreferenceFilter($params);

        // meal_plan_preference (the hotel board-tier question) deliberately does NOT map to a
        // Booking `mealplan=` filter — removed 2026-09-02 (owner's call, same session as the
        // accommodationNightlyPriceCeiling fix above). Real live research showed board-plan
        // inventory is too thin/inconsistent per destination for a mealplan= filter to be safe:
        // within the SAME town, breakfast-only jumped from ~equal-to-no-meal price straight to a
        // lone, wildly expensive outlier listing once dinner was added (owner's examples:
        // Rethymno 200€/no-meal -> 220€/breakfast -> 2500€/half-board, all for the same 8
        // nights) — narrowing the search to mealplan= risked surfacing that one skewed property,
        // or nothing at all, instead of the traveler's real best options. The price ceiling
        // already reflects the full stated budget with no board-plan deduction (see
        // accommodationNightlyPriceCeiling); matched_tags/Honest Report still communicate what
        // was asked for. A future campaign scoped to genuinely all-inclusive-heavy destinations
        // (see kampanje.md) is the right place to reintroduce a real mealplan filter, backed by
        // real captured includes_meals prices instead of an estimate.

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
     * link wrapper goes around it later.
     *
     * Bug fixed 2026-08-23 (owner caught it live: picked Breakfast/AC/Balcony/All-inclusive in
     * the wizard, clicked through to a real Booking search, and NONE of it was reflected — the
     * page showed unfiltered, unsorted results). Root cause: toBookingParams()'s rich `filters`
     * shape was built assuming a FUTURE Partner/Demand API request body, and this method never
     * read it at all. Now reuses that same filter computation and translates it into Booking's
     * real public URL filter parameter — `nflt` (semicolon-delimited `category=id` chips) and
     * `order` for sort — format confirmed via developers.booking.com's own filter/sort docs
     * (2026-08-23) using the exact hotelfacility/roomfacility/mealplan IDs already captured
     * manually from a live site export (see applyAmenityYesFilters's docblock). `kvalitet` ->
     * `order=class` (rating high-to-low) confirmed the same way, same day. A real price ceiling
     * — computed from total_budget minus an estimated food cost, see
     * accommodationNightlyPriceCeiling()'s docblock — also confirmed the same day
     * ("price=EUR-min-140-1"). `stay_type` chips (e.g. Pets allowed) added 2026-08-24, same real
     * "Travel group" filter-sidebar export as the amenity IDs. `accommodation_types` (ht_id)
     * chips added 2026-09-02 — real key confirmed via BookingPriceLinkGenerator's own already-
     * working research links (same `ht_id={id}` nflt chip) plus a fresh owner DOM capture for
     * Resorts (`data-filters-item="ht_id:ht_id=206"`). Bug caught live the same day: the new
     * accommodation_type_preference question (see applyAccommodationTypePreferenceFilter) was
     * correctly populating `filters['accommodation_types']`, but this method's nflt builder
     * never read that key at all — a real wizard session's Booking link never actually filtered
     * by property type despite the traveler's (or the default-all-selected prefill's) picks.
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

        $params = [
            'checkin' => $checkin->toDateString(),
            'checkout' => $checkout->toDateString(),
            'group_adults' => $this->session->adults_count ?: 1,
            'no_rooms' => $this->session->number_of_rooms ?: 1,
            'selected_currency' => 'EUR',
        ];

        // Bug fixed 2026-09-03 (owner caught it live testing Ischia in the admin price tool): a
        // plain `ss` text search can resolve to the WRONG Booking destination entity when a
        // place's name collides with a narrower one — "Ischia" alone matched a small ~10-hotel
        // locality (the port town) instead of the ~500-hotel whole-island region a traveler
        // actually means. Real, owner-verified dest_id/dest_type (captured directly from
        // Booking's own search box, not the "test_*_city" PLACEHOLDER every Location row got at
        // seed time — see seedSwimDestinations' docblock, that placeholder era is exactly why
        // this class avoided dest_id entirely until now) reuses the SAME Location record this
        // relation already pointed at, just with real values — used INSTEAD of `ss` when
        // present (Booking's real search-results page omits `ss` entirely once dest_id/dest_type
        // are set, confirmed from the owner's own captured URL). `source` distinguishes a real
        // capture from the placeholder — never trust a 'manual_test' row here.
        $location = $destination->bookingLocation;
        if ($location && $location->source !== 'manual_test') {
            $params['dest_id'] = $location->booking_dest_id;
            $params['dest_type'] = $location->dest_type;
        } else {
            $params['ss'] = $destination->parent ? "{$destination->label}, {$destination->parent->label}" : $destination->label;
        }

        $childrenAges = $this->session->children_ages ?? [];
        if (! empty($childrenAges)) {
            $params['group_children'] = count($childrenAges);
        }

        $filters = $this->toBookingParams()['filters'] ?? [];

        $nfltChips = [];
        foreach ($filters['accommodation_facilities'] ?? [] as $id) {
            $nfltChips[] = "hotelfacility={$id}";
        }
        foreach ($filters['room_facilities'] ?? [] as $id) {
            $nfltChips[] = "roomfacility={$id}";
        }
        foreach ($filters['meal_plan'] ?? [] as $id) {
            $nfltChips[] = "mealplan={$id}";
        }
        foreach ($filters['stay_types'] ?? [] as $id) {
            $nfltChips[] = "stay_type={$id}";
        }
        foreach ($filters['accommodation_types'] ?? [] as $id) {
            $nfltChips[] = "ht_id={$id}";
        }
        foreach ($filters['privacy_types'] ?? [] as $id) {
            $nfltChips[] = "privacy_type={$id}";
        }
        foreach ($filters['popular_activities'] ?? [] as $id) {
            $nfltChips[] = "popular_activities={$id}";
        }
        if ($ceiling = $this->accommodationNightlyPriceCeiling()) {
            $nfltChips[] = "price=EUR-min-{$ceiling}-1";
        }
        if (! empty($nfltChips)) {
            $params['nflt'] = implode(';', $nfltChips);
        }

        // Real top-level param, not an nflt chip — see applyFamilyFriendlyFilter's docblock,
        // sourced the same way (a live filter-sidebar export), not guessed.
        if (! empty($filters['family_friendly_property'])) {
            $params['family_friendly_property'] = 1;
        }

        // kvalitet -> order=class (rating high-to-low) confirmed 2026-08-23, same live-capture
        // method as everything else here — owner found it while testing the price sort. jeftino/
        // kvalitet are mutually exclusive selections (see seedPreferenceTags' excludes relation),
        // so at most one of these ever fires; jeftino checked first only as a stable tie-break
        // if that relation is ever bypassed (e.g. a session seeded directly in a test). Neither
        // picked -> order=review_score_and_price ("Best reviewed and lowest price", owner's real
        // capture 2026-09-02) instead of leaving order unset — a real default sort beats
        // whatever arbitrary order Booking falls back to on its own.
        $tags = $this->allPreferenceTagSlugs();
        if ($tags->contains('jeftino')) {
            $params['order'] = 'price';
        } elseif ($tags->contains('kvalitet')) {
            $params['order'] = 'class';
        } else {
            $params['order'] = 'review_score_and_price';
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

        return $this->wrapWithAffiliateTracking('https://www.booking.com/searchresults.html?'.implode('&', $query));
    }

    /**
     * Hotels.com point-of-sale per visitor language, confirmed 2026-09-24 from two real URLs the
     * owner captured after switching the site's own region/currency selector: the currency is NOT a
     * cookie-only setting, it lives in the URL (`currency=EUR`, plus `siteid` + `locale`), and Germany
     * is its own domain. Forcing EUR matters because `price=` (our budget-derived cap, computed in
     * EUR) is read in whatever currency the visitor's session uses — the owner's own US-POS session
     * showed "Less than $2,625" for a €2625 cap (~9% too strict), and a hardcoded FX rate would have
     * been wrong for anyone already seeing EUR. English visitors get the Irish English EUR POS
     * (`en_IE`, the only EUR-priced English POS captured), German visitors the German one.
     *
     * Every link stays on `www.hotels.com` and only `siteid`/`locale` differ: pointing the affiliate
     * wrapper's `landingPage` at `de.hotels.com` returns Hotels.com's "Page not found" (owner's live
     * test, 2026-09-24), so the German POS must be selected by parameters, same mechanism that
     * already worked for English.
     *
     * @var array<string, array{siteid: string, locale: string}>
     */
    private const HOTELS_POS = [
        'de' => ['siteid' => '300000752', 'locale' => 'de_DE'],
        'en' => ['siteid' => '300000025', 'locale' => 'en_IE'],
    ];

    /**
     * Who sleeps in which room, in Hotels.com's real query shape — confirmed 2026-09-24 from a URL
     * the owner captured for "2 parents, 3 children, 2 rooms":
     * `adults=1,1&rooms=2&children=1_10,1_8,2_2`, i.e. `adults` is a comma list with one entry PER
     * ROOM, and `children` is a comma list of `<room>_<age>` (room numbers start at 1; an infant
     * is age 0). Previously the link sent a bare `adults=<total>&rooms=<n>` and no children at all.
     *
     * The session only knows totals (adults, children ages, number of rooms — the wizard sets the
     * latter itself, ceil(travelers/3) when a 4-5 person group isn't staying together), not who is
     * in which room, so this picks a sensible split: a room needs at least one adult, so rooms are
     * capped at the adult count; adults go as evenly as possible (extras to the first rooms);
     * children are dealt round-robin, which keeps room sizes balanced.
     *
     * @return array{adults: string, rooms: int, children: ?string}
     */
    private function hotelsOccupancy(): array
    {
        $adults = max(1, (int) $this->session->adults_count);
        $rooms = max(1, min((int) ($this->session->number_of_rooms ?: 1), $adults));

        $adultsPerRoom = array_fill(0, $rooms, intdiv($adults, $rooms));
        for ($i = 0; $i < $adults % $rooms; $i++) {
            $adultsPerRoom[$i]++;
        }

        $childrenByRoom = array_fill(1, $rooms, []);
        foreach (array_values($this->session->children_ages ?? []) as $i => $age) {
            $childrenByRoom[($i % $rooms) + 1][] = max(0, (int) $age);
        }

        $children = [];
        foreach ($childrenByRoom as $room => $ages) {
            foreach ($ages as $age) {
                $children[] = "{$room}_{$age}";
            }
        }

        return [
            'adults' => implode(',', $adultsPerRoom),
            'rooms' => $rooms,
            'children' => $children === [] ? null : implode(',', $children),
        ];
    }

    /**
     * A REAL, working public hotels.com search URL — same "no API key, no partner approval"
     * spirit as toBookingUrl() above, built from a real captured example: the owner ran an
     * actual search (Prague, 2026-10-09 to 2026-10-12, 2 adults) and sent the resulting URL.
     * Deliberately minimal — only params confirmed to actually matter. `typeaheadCollationId`
     * (a UUID tied to Hotels.com's own autocomplete widget) and `pwaDialog` (UI state — some
     * dialog that happened to be open when the URL was captured) were both dropped and
     * re-tested without them, live, 2026-09-08 — search still resolved correctly (site itself
     * expanded the plain city name to a full "City, Country" + its own internal regionId via
     * redirect, same "plain text destination, let the site resolve it" shape as toBookingUrl()'s
     * `ss` fallback branch).
     *
     * Every parameter here comes from a real captured Hotels.com URL — don't guess names, add
     * them once a real example exists, same discipline as everything else in this class. Since
     * the first version: amenities (see applyHotelsAmenitiesFilter), `sort`, point-of-sale/
     * currency (HOTELS_POS), and adults/rooms/children (hotelsOccupancy) were all captured and
     * added.
     *
     * `travelerType`/`star`/`guestRating` (2026-09-08, owner's explicit ask to build ahead of
     * real data — "uradi ga sad, testiramo kad unesem prave vrednosti") — confirmed real
     * parameter names/values, see HotelsComFilters. `lgbtq_welcoming` had real coverage when
     * tested (63 results, Prague); `romantic` was tested and confirmed near-zero coverage
     * (Prague AND Cyprus both empty) so it's deliberately never sent regardless of any future
     * relationship_type mapping. `star=40`/`guestRating=40` (4+ stars / 8+ rating) for the
     * `kvalitet` preference are UNVERIFIED for real per-destination coverage the way
     * lgbtq_welcoming/romantic were — common enough attributes that zero-coverage seems unlikely,
     * but not confirmed live the same way. `travelerType` supports multiple values (a real HTML
     * checkbox group, not a single-value field) — built by hand like toBookingUrl()'s repeated
     * `age=` params, not through the flat $params map.
     */
    public function toHotelsUrl(string $locale = 'en'): ?string
    {
        $destination = $this->destinationNode();
        if (! $destination) {
            return null;
        }

        [$checkin, $checkout] = $this->resolveDates();
        if (! $checkin) {
            return null;
        }

        $pos = self::HOTELS_POS[$locale] ?? self::HOTELS_POS['en'];

        $occupancy = $this->hotelsOccupancy();

        $params = [
            'destination' => $destination->label,
            'startDate' => $checkin->toDateString(),
            'endDate' => $checkout->toDateString(),
            'adults' => $occupancy['adults'],
            'rooms' => $occupancy['rooms'],
            'flexibility' => '0_DAY',
            'siteid' => $pos['siteid'],
            'locale' => $pos['locale'],
            'currency' => 'EUR',
        ];

        // Children, 2026-09-24 (owner's live test: 2 adults + 2 children in the wizard landed on a
        // 2-adult Hotels.com search — the Hotels link never sent children at all, unlike
        // toBookingUrl()'s group_children/age).
        if ($occupancy['children'] !== null) {
            $params['children'] = $occupancy['children'];
        }

        $tags = $this->allPreferenceTagSlugs();

        if ($tags->contains('kvalitet')) {
            $params['star'] = 40;
            // 8+ rating, not 9+ — HotelsComFilters::GUEST_RATING's own docblock: 40="Very good
            // 8+", 45="Wonderful 9+". Was wrongly 45 until 2026-09-09 (copy-paste from STAR's
            // scale, never cross-checked against GUEST_RATING's actual meaning) — the owner's
            // repeated "8+ rating" ask (see kampanje.md/GeographyResolver's kvalitet comments)
            // was quietly being served a 9+ filter instead.
            $params['guestRating'] = 40;
        }

        // sort=PRICE_LOW_TO_HIGH (jeftino) / sort=REVIEW_RELEVANT (kvalitet) — both real,
        // owner-captured 2026-09-23 from live sort-dropdown clicks on the same city/dates (same
        // "sort" question the owner asked about after building the interpolated-pricing tool:
        // "da l je order po ceni ako biram jeftino a po rekomended ako biram kvalitet"). Mirrors
        // toBookingUrl()'s order= block above/elsewhere in this class — jeftino/kvalitet are
        // mutually exclusive (seedPreferenceTags' excludes relation), jeftino checked first only
        // as a stable tie-break if that relation is ever bypassed. Neither picked -> ALSO
        // REVIEW_RELEVANT ("Sort by guest rating + our picks" in Hotels.com's own dropdown), owner's
        // call 2026-09-24 — it was previously left unset (Hotels.com's plain "Recommended"), but the
        // owner prefers guest-rating-first as the default, same idea as toBookingUrl()'s
        // review_score_and_price default.
        $params['sort'] = $tags->contains('jeftino') ? 'PRICE_LOW_TO_HIGH' : 'REVIEW_RELEVANT';

        $query = [];
        foreach ($params as $key => $value) {
            $query[] = $key.'='.rawurlencode((string) $value);
        }

        // zeli_lgbt_friendly -> travelerType=lgbtq_welcoming, same cultural_availability signal
        // filterByCulturalAvailability reads elsewhere. Only 'romantic' was tested and dropped —
        // no other traveler-experience filter maps to an existing session signal yet.
        if ($tags->contains('zeli_lgbt_friendly')) {
            $query[] = 'travelerType='.rawurlencode('lgbtq_welcoming');
        }

        // porodicna_atmosfera -> travelerType=family_friendly, confirmed 2026-09-24 from a real
        // Hotels.com URL after clicking its "Family friendly" filter (owner's capture, Skiathos).
        // Same signal toBookingParams() already maps to Booking's family_friendly_property: the
        // `porodica` group_type suggests this tag (seedRelations) and the wizard auto-picks that
        // group as soon as children are entered, so a family session gets it without extra
        // clicks. Repeated `travelerType=` when combined with lgbtq_welcoming — same repeated-
        // param shape as room_amenities_group, which Hotels.com accepted live.
        if ($tags->contains('porodicna_atmosfera')) {
            $query[] = 'travelerType='.rawurlencode('family_friendly');
        }

        // Real price range, 2026-09-17/18 — owner caught it live across three captures. First,
        // a single-handle test showed `&price=77&price=-2` (the `-2` read as a slider-precision
        // artifact, not a real minimum). Two later captures with both handles genuinely moved
        // (`&price=855&price=2410`, `&price=621&price=1719`) confirmed the real shape: TWO
        // repeated `price=` params, a min and a max, both TOTAL stay cost (not per-night like
        // Booking's own `price=EUR-min-{ceiling}-1`) and NEVER the raw stated total_budget
        // figure — always min=0 (owner's explicit call: "donja treba da bude 0 a gornja kolko
        // proracunas... nikad ono kolko definisemo ko budzet") paired with whatever we actually
        // calculate as the max. accommodationNightlyPriceCeiling() returns a per-night figure
        // (shared with Booking's own use of it) — multiplied by nights here for the max, since
        // sending it unmultiplied would silently zero out every real result on a multi-night stay.
        if ($ceiling = $this->accommodationNightlyPriceCeiling()) {
            $query[] = 'price=0';
            $query[] = 'price='.($ceiling * $checkin->diffInDays($checkout));
        }

        $this->applyHotelsLodgingTypeFilter($query);
        $this->applyHotelsMealPlanFilter($query);
        $this->applyHotelsAmenitiesFilter($query);

        return $this->wrapWithHotelsAffiliateTracking('https://www.hotels.com/Hotel-Search?'.implode('&', $query));
    }

    /**
     * A tracked Hotels.com link that works with NO destination/dates resolved yet — 2026-09-17,
     * owner's ask: every plain-text "Hotels.com" brand mention (footer disclosure, the "Why
     * Hotels.com?" greeting line) should itself be a real affiliate link, not just naming the
     * brand, so even a casual click plants the 7-day cookie (see the Travel Creator Program
     * cookie terms — ANY booking on the site counts, not just a specific searched item). Wraps
     * the bare homepage rather than toHotelsUrl()'s destination search — this is meant to work on
     * every page load, including before any destination is picked. Same graceful fallback as
     * toHotelsUrl() (wrapWithHotelsAffiliateTracking already returns the plain URL if the
     * expedia.* config is unset).
     */
    public function genericHotelsUrl(): string
    {
        return $this->wrapWithHotelsAffiliateTracking('https://www.hotels.com/');
    }

    /**
     * accommodation_type_preference -> Hotels.com's `lodging` filter (HotelsComFilters::
     * LODGING_TYPES), 2026-09-09. Same source question as applyAccommodationTypePreferenceFilter
     * (Booking side) and the same "harmless to send every id, opt-out/default-all-selected
     * behaves like no filter" assumption — carried over from Booking's confirmed behavior, NOT
     * independently verified for Hotels.com yet (spot-check live before trusting it narrows
     * anything). Only maps the 6 real ht_id-style tip_smestaja nodes with a genuine 1:1 category
     * match (hotel/apartman/vila/holiday_home/guest_house/chalet) — 'ceo_smestaj' ("Entire homes
     * & apartments") is deliberately UNMAPPED: that's Booking's own privacy_type filter, and no
     * equivalent has been confirmed in Hotels.com's captured filter set, so it's left absent
     * rather than guessed at.
     *
     * Query format for multiple values is UNVERIFIED — repeated `lodging=` keys is the common
     * REST convention and what's used here, but no real multi-select capture confirms Hotels.com
     * reads it this way (vs comma-joined or `lodging[]=`). Cheap to verify live during the Friday
     * price-research pass — see kampanje.md.
     */
    private function applyHotelsLodgingTypeFilter(array &$query): void
    {
        $slugs = $this->session->free_text_answers['accommodation_type_preference'] ?? [];
        if (empty($slugs)) {
            return;
        }

        $ids = TaxonomyNode::where('type', 'tip_smestaja')->whereIn('slug', $slugs)->pluck('meta')
            ->flatMap(fn (?array $meta) => $meta['hotels_lodging_type_ids'] ?? [])
            ->unique();

        foreach ($ids as $id) {
            $query[] = 'lodging='.rawurlencode((string) $id);
        }
    }

    /**
     * meal_plan_preference -> Hotels.com's `mealPlan` filter (HotelsComFilters::MEAL_PLAN),
     * 2026-09-09. Deliberately the OPPOSITE call from toBookingParams()'s meal_plan_preference
     * comment just above: Booking's own board-plan inventory was too thin/inconsistent to filter
     * on safely (2x-12x price jumps for the same plan, same town — see that comment), but that
     * was a statement about BOOKING's inventory, not a universal fact about board-plan filtering.
     * This is exactly the strength the owner spotted in Hotels.com's own filter sidebar (real,
     * well-populated Free breakfast/Half board/All-inclusive checkboxes with real counts) that
     * Booking's messy inventory had forced us to abandon — safe to wire in here, unrelated
     * decision. Sends every selected preference's id (multiple picks allowed, same "any of these
     * are fine" semantics as accommodation_type_preference) — same unverified multi-value query
     * format caveat as applyHotelsLodgingTypeFilter above.
     */
    private function applyHotelsMealPlanFilter(array &$query): void
    {
        $slugs = $this->session->free_text_answers['meal_plan_preference'] ?? [];
        if (empty($slugs)) {
            return;
        }

        $ids = TaxonomyNode::where('type', 'meal_plan')->whereIn('slug', $slugs)->pluck('meta')
            ->pluck('hotels_meal_plan_id')
            ->filter()
            ->unique();

        foreach ($ids as $id) {
            $query[] = 'mealPlan='.rawurlencode((string) $id);
        }
    }

    /**
     * amenities_yes -> Hotels.com's FOUR separate amenity-family query params, 2026-09-18 —
     * confirmed directly against a real full sidebar capture (data/sidebar.html) rather than
     * guessed. Unlike Booking's single `applyAmenityYesFilters` (one nested `filters.*` shape),
     * Hotels.com splits this across `amenities=` (property-level, HotelsComFilters::AMENITIES),
     * `room_amenities_group=` (a physical room feature, HotelsComFilters::ROOM_AMENITIES),
     * `room_views_group=` (a view, HotelsComFilters::ROOM_VIEWS), and `beach_access_group=`
     * (HotelsComFilters::BEACH_ACCESS, `plaza`/"Beachfront" -> `on_the_beach` — genuinely
     * relevant for kasno-letovanje specifically) — routed here by which meta key each node
     * actually carries (`hotels_amenity_id`/`hotels_room_amenity_id`/`hotels_room_view_id`/
     * `hotels_beach_access_id`, set in WizardSeeder), not by the node's own taxonomy type — e.g.
     * `vesmasina` is a room_facility in our taxonomy but only exists as Hotels.com's
     * property-level WASHER_DRYER, so it carries `hotels_amenity_id` despite its type. Only
     * confirmed 1:1 matches got a meta key at all; everything else is silently absent (same
     * "confirmed real, not guessed" discipline as the rest of this class), so a lot of
     * amenities_yes picks legitimately add nothing to this URL yet. Query format for multiple
     * values is unverified for all four params, same caveat as applyHotelsLodgingTypeFilter.
     */
    private function applyHotelsAmenitiesFilter(array &$query): void
    {
        $slugs = $this->session->free_text_answers['amenities_yes'] ?? [];
        if (empty($slugs)) {
            return;
        }

        $metas = TaxonomyNode::whereIn('type', ['accommodation_facility', 'room_facility'])
            ->whereIn('slug', $slugs)
            ->pluck('meta');

        foreach (['hotels_amenity_id' => 'amenities', 'hotels_room_amenity_id' => 'room_amenities_group', 'hotels_room_view_id' => 'room_views_group', 'hotels_beach_access_id' => 'beach_access_group'] as $metaKey => $paramName) {
            $ids = $metas->pluck($metaKey)->filter()->unique();
            foreach ($ids as $id) {
                $query[] = $paramName.'='.rawurlencode((string) $id);
            }
        }
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

        return $this->wrapWithAffiliateTracking('https://flights.booking.com/fly-anywhere/?'.implode('&', $query));
    }

    /**
     * Wraps a public Booking.com URL in the CJ (Commission Junction) deep-link redirect so a
     * resulting booking actually earns commission — see config/services.php's 'cj' block
     * docblock for how pid/link_id were obtained and confirmed live. Falls back to the plain
     * unwrapped URL when either is unset (local/dev, or if the affiliate relationship ever
     * lapses) — never breaks the link, just stops tracking it.
     */
    private function wrapWithAffiliateTracking(string $url): string
    {
        $pid = config('services.cj.pid');
        $linkId = config('services.cj.link_id');

        if (! $pid || ! $linkId) {
            return $url;
        }

        return "https://www.dpbolvw.net/click-{$pid}-{$linkId}?url=".rawurlencode($url);
    }

    /**
     * NOT the CJ pattern above — Hotels.com/Expedia is reached via the separate Expedia Group
     * Travel Creator Program (approved 2026-09-16, after CJ's own Hotels.com listing rejected the
     * application twice). See config/services.php's 'expedia' block docblock for how
     * camref/creativeref/adref were confirmed live and safe to hold as static config. Same
     * graceful-fallback shape as wrapWithAffiliateTracking() — falls back to the plain unwrapped
     * URL if any of the three is unset.
     */
    private function wrapWithHotelsAffiliateTracking(string $url): string
    {
        $camref = config('services.expedia.camref');
        $creativeref = config('services.expedia.creativeref');
        $adref = config('services.expedia.adref');

        if (! $camref || ! $creativeref || ! $adref) {
            return $url;
        }

        return 'https://www.hotels.com/affiliate?landingPage='.rawurlencode($url)
            ."&camref={$camref}&creativeref={$creativeref}&adref={$adref}";
    }

    /**
     * meal_style=sam_se_snalazim ("I'll organize myself / cook") -> Booking's real
     * `mealplan=999` (Self catering) filter. Split off from applyMealPlanPreferenceFilter,
     * 2026-08-13, alongside meal_style becoming its own mandatory question — the
     * `sam_se_snalazim` taxonomy node itself carries the real booking_meal_plan_id (999), same
     * value the old `samostalno_kuvanje` meal_plan-type node used to carry before it was removed.
     *
     * Bug fixed 2026-09-02: this checked for slug `'kuva_sam'`, which no meal_style node has ever
     * carried (seedMealStyles seeds `sam_se_snalazim`) — the real ht_id=999 filter silently never
     * applied to a single self-catering session's Booking link since this method was split out.
     */
    private function applyMealStyleFilter(array &$params): void
    {
        if (($this->session->free_text_answers['meal_style'] ?? null) !== 'sam_se_snalazim') {
            return;
        }

        $meta = TaxonomyNode::where('type', 'meal_style')->where('slug', 'sam_se_snalazim')->value('meta');
        $bookingId = $meta['booking_meal_plan_id'] ?? null;
        if ($bookingId) {
            $params['filters']['meal_plan'] = array_values(array_unique([...($params['filters']['meal_plan'] ?? []), $bookingId]));
        }
    }

    /**
     * accommodation_type_preference (2026-09-02, first live UI for the `tip_smestaja` taxonomy
     * type — see toBookingParams()'s dormant FK comment) -> real Booking `accommodation_types`
     * (ht_id) filter, PLUS `privacy_types` (Booking's separate `privacy_type=` dimension — only
     * 'ceo_smestaj'/"Entire homes & apartments" lives there today, added 2026-09-02, real DOM
     * capture: `data-filters-item="ht_id:privacy_type=3"`). Default-all-selected/opt-out at the
     * wizard-question level (see WizardComponent.prefillAccommodationTypePreference) means an
     * untouched answer already contains every seeded tip_smestaja slug — sending all of their
     * ids is harmless (only 7 types total exist, nowhere near the URL-length limit that broke an
     * earlier, much larger attempt — see BookingPriceLinkGenerator's docblock) and behaviorally
     * identical to omitting the filter (Booking's own "nothing/everything checked = show
     * everything" default). accommodation_types merges with (does not replace) the dormant
     * FK-based path above.
     */
    private function applyAccommodationTypePreferenceFilter(array &$params): void
    {
        $slugs = $this->session->free_text_answers['accommodation_type_preference'] ?? [];
        if (empty($slugs)) {
            return;
        }

        $metas = TaxonomyNode::where('type', 'tip_smestaja')->whereIn('slug', $slugs)->pluck('meta');

        $accommodationTypeIds = $metas->flatMap(fn (?array $meta) => $meta['booking_accommodation_type_ids'] ?? [])->unique()->values()->all();
        if (! empty($accommodationTypeIds)) {
            $params['filters']['accommodation_types'] = array_values(array_unique([...($params['filters']['accommodation_types'] ?? []), ...$accommodationTypeIds]));
        }

        $privacyTypeIds = $metas->flatMap(fn (?array $meta) => $meta['booking_privacy_type_ids'] ?? [])->unique()->values()->all();
        if (! empty($privacyTypeIds)) {
            $params['filters']['privacy_types'] = array_values(array_unique([...($params['filters']['privacy_types'] ?? []), ...$privacyTypeIds]));
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

        $nodes = TaxonomyNode::whereIn('type', ['tip_smestaja', 'accommodation_facility', 'room_facility', 'meal_plan', 'stay_type', 'popular_activity'])
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
                'stay_type' => $params['filters']['stay_types'][] = $node->meta['booking_stay_type_id'] ?? null,
                'popular_activity' => $params['filters']['popular_activities'][] = $node->meta['booking_popular_activity_id'] ?? null,
                default => null,
            };
        }

        foreach (['accommodation_facilities', 'room_facilities', 'meal_plan', 'stay_types', 'popular_activities'] as $key) {
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
        $sameUnit = WizardCampaignDestinationPrice::wantsSameUnit($totalTravelers, $this->session->number_of_rooms);
        // Splits nights across whichever campaign weeks they fall in, priced per-week — see
        // WizardCampaignDestinationPrice::estimateAccommodationTotal(), 2026-08-11. Falls back
        // to the old flat price_per_person_eur * days math internally if this destination has
        // no weekly rows yet. $totalTravelers is translated into a sum of real apartment-
        // occupancy multipliers internally (roomMultiplierSumFor()), not multiplied directly.
        // $qualityTier, 2026-09-24 — same rule GeographyResolver::filterByBudget/accommodationTotalFor
        // already apply: a `kvalitet` session prices accommodation at the real 8+/4-star tier (when
        // that week has one, else the regular price). Without this the Honest Report's budget fit
        // was computed on the REGULAR price while the wizard cards the traveler just clicked
        // through were computed on the quality one — same session, two different totals.
        $qualityTier = $this->allPreferenceTagSlugs()->contains('kvalitet');
        $accommodationTotal = $priceRow !== null ? $priceRow->estimateAccommodationTotal($checkin, $checkout, $totalTravelers, $sameUnit, $qualityTier) : 0.0;

        return [
            'country' => $country,
            'estimate' => $estimate,
            'accommodation_total_eur' => $accommodationTotal,
            'meals_included' => (bool) $priceRow?->includes_meals,
        ];
    }

    /**
     * Real per-night accommodation price ceiling for Booking's `price=EUR-min-{X}-1` URL
     * filter — owner's ask, 2026-08-23, after finding that filter's real format from a live
     * capture ("izabrao sam da je cena manja od 140 evra po noci"). Works backward from the
     * wizard's own total_budget: subtracts however much of that budget is realistically going
     * to FOOD, and whatever's left, divided by nights, becomes the ceiling.
     *
     * Deliberately does NOT reuse resolveBudgetContext() — that one zeroes the food estimate
     * when the destination's own price row is flagged includes_meals, which is exactly backward
     * for this purpose (a bundled all-inclusive PRICE already reflects meal cost; here we still
     * need a real food-total to subtract from the traveler's STATED budget, regardless of
     * whether the specific price row we might eventually book happens to bundle it).
     *
     * meal_style branches (see BudgetEstimationEngine::fitFor's docblock for the same three
     * values elsewhere in this class):
     *  - 'jede_napolju' (restaurants): subtract the full eating-out estimate.
     *  - 'sam_se_snalazim' (self-catering): subtract the full self-catering estimate.
     *  - 'u_smestaju' (hotel meal plan): subtracts NOTHING — the full total_budget becomes the
     *    ceiling. Bug fixed 2026-09-02: this used to subtract only the out-of-pocket leftover
     *    (BudgetEstimationEngine::outOfPocketMealTotal), on the assumption that the covered
     *    portion is "already priced into" whatever room rate Booking shows — true in principle,
     *    but the real board-supplement premium a meal-plan room actually charges over an
     *    equivalent no-meal room varies wildly by destination (owner's live research, same day:
     *    Öludeniz ~2x for half-board, another town ~2.5x, Rethymno ~12x on its cheapest available
     *    half-board listing) — no single markup factor comes close to fitting all of them, and
     *    UNDER-shooting the ceiling silently excludes the very meal-plan rooms Booking's own
     *    `mealplan=` filter is supposed to surface (blank/near-blank results, same failure mode
     *    as the earlier Alanya nights-vs-days ceiling bug). Trusting the full stated budget as
     *    the ceiling, combined with Booking's own mealplan filter already narrowing to matching
     *    properties, is the honest option: if the traveler's budget genuinely can't cover a
     *    real meal-plan room here, that surfaces as few/no results rather than a falsely
     *    confident (and wrong) price cutoff.
     *  - anything else (not yet answered): null, no price filter added at all — same "absent,
     *    not a guess" convention as everything else in this class.
     *
     * Rounds DOWN, not to nearest — never let the filter exclude a property that's genuinely
     * still in budget by a rounding hair.
     */
    private function accommodationNightlyPriceCeiling(): ?int
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
        // Real bug caught live, 2026-09-01 (owner: a 500€/8-night/breakfast+dinner/Alanya
        // session came back with a €35 ceiling instead of the real €39 — blank Booking results,
        // since Alanya's actual rates researched this same day start around €50+). $days (food
        // convention, +1 — you still eat on checkout morning) was being reused as the NIGHTLY
        // divisor too, understating the ceiling. $nights (no +1, matches
        // WizardCampaignDestinationPrice's own convention) is what accommodation actually divides
        // by — $days stays food-only.
        $days = $checkin->diffInDays($checkout) + 1;
        $nights = $checkin->diffInDays($checkout);
        if ($days < 1 || $nights < 1) {
            return null;
        }

        $children = count($this->session->children_ages ?? []);
        $engine = new BudgetEstimationEngine;
        $estimate = $engine->estimate($country, $this->session->adults_count, $children, $days);
        if ($estimate === null) {
            return null;
        }

        $mealStyle = $this->session->free_text_answers['meal_style'] ?? null;
        $mealPlanSlugs = $this->session->free_text_answers['meal_plan_preference'] ?? [];

        $foodTotal = match (true) {
            $mealStyle === 'jede_napolju' => $estimate['eating_out_total_eur'],
            $mealStyle === 'sam_se_snalazim' => $estimate['self_catering_total_eur'],
            // 0.0, not outOfPocketMealTotal() — see this method's docblock, 2026-09-02: the real
            // board-supplement premium a meal-plan room charges varies too wildly by destination
            // (2x-12x, owner's live research) for any subtracted estimate to avoid silently
            // under-shooting the ceiling and excluding real meal-plan rooms from the search.
            $mealStyle === 'u_smestaju' && ! empty($mealPlanSlugs) => 0.0,
            default => null,
        };

        if ($foodTotal === null) {
            return null;
        }

        $accommodationBudgetTotal = ((float) $this->session->total_budget) - $foodTotal;
        if ($accommodationBudgetTotal <= 0) {
            return null;
        }

        return (int) floor($accommodationBudgetTotal / $nights);
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

        // Bug fixed 2026-09-02 (owner's ask, live on the real kasno-letovanje flow): the
        // recommended date default used to anchor to `window_start` (kasno_kupanje's fixed
        // "09-19" marker), landing weeks into the future the moment today passed that date —
        // not a useful "here's a starting point" suggestion. Anchoring to the next upcoming
        // weekday instead (checkin today if today IS that weekday) always gives an immediately
        // actionable default. Saturday+7 nights remains the default (matches kasno-letovanje/
        // zimsko-sunce's own Saturday-aligned weekly pricing, see WizardCampaign::seasonWeeks())
        // — `default_duration_days` is deliberately NOT reused here (still drives
        // presetTripLengthDays' budget estimate instead, a separate concern).
        //
        // `default_checkin_weekday`/`default_stay_nights` (2026-09-17, owner's ask) — a shorter
        // campaign gets its own real default instead of inheriting the 7-night swim-holiday
        // shape: Jesenjovanje presets Friday->Sunday (2 nights), a realistic city-break length,
        // via jesenji_gradski_bek's own meta. Absent on every other termin_category, so
        // kasno_kupanje/zimsko_sunce behave exactly as before.
        $checkinWeekday = $termin?->meta['default_checkin_weekday'] ?? Carbon::SATURDAY;
        $stayNights = $termin?->meta['default_stay_nights'] ?? 7;

        $checkin = $this->nextWeekday($checkinWeekday);
        $checkout = $checkin->copy()->addDays($stayNights);

        return [$checkin, $checkout];
    }

    /** The next occurrence of the given weekday (Carbon::SATURDAY etc.) from today, inclusive —
     *  today itself if today already is that weekday. */
    private function nextWeekday(int $weekday): Carbon
    {
        $today = Carbon::today();

        return $today->copy()->addDays(($weekday - $today->dayOfWeek + 7) % 7);
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
