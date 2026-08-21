<?php

namespace Database\Seeders;

use App\Models\CulturalAvailability;
use App\Models\Holiday;
use App\Models\HolidayPricingWindow;
use App\Models\Location;
use App\Models\TaxonomyNode;
use App\Models\TaxonomyNodeAccommodationSeason;
use App\Models\TaxonomyNodeClimate;
use App\Models\TaxonomyNodeRelation;
use App\Models\WizardCampaign;
use App\Models\WizardStep;
use App\Models\WizardQuestion;
use Illuminate\Database\Seeder;

class WizardSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedTripTypes();
        $this->seedGroupTypes();
        $this->seedPersonas();
        $this->seedRelationshipType();
        $this->seedMealStyles();
        $this->seedTerminCategories();
        $this->seedPreferenceTags();
        $this->seedBudgetTiers();
        $this->seedAmenities();
        $this->seedGeography();
        $this->seedCostCategories();
        $this->seedClimate();
        $this->seedLocations();
        $this->seedSwimDestinations();
        $this->seedSwimCountryProfiles();
        $this->seedCityAndCountryVibeProfiles();
        $this->seedSwimAtmosphereTags();
        $this->propagateCountryDrinksToCities();
        $this->seedExplorationAndBeachTags();
        $this->seedRomanticTags();
        $this->seedFamilyAndQuietTags();
        $this->propagateCityAtmosphereToCountry();
        $this->seedAccommodationSeasons();
        $this->seedHolidayPricingWindows();
        $this->seedHolidays();
        $this->seedWizardSteps();
        $this->seedWizardCampaigns();
        $this->seedRelations();
        $this->seedGermanTranslations();
    }

    /**
     * Create a taxonomy node with an English canonical label, plus a seeded Serbian
     * translation (status 'human' — we're authoring it directly, not AI-generating it).
     * See wizard_architecture / i18n decision: English is always the canonical source.
     */
    private function node(string $type, string $slug, string $labelEn, string $labelSr, int $sortOrder, ?array $meta = null): TaxonomyNode
    {
        $node = TaxonomyNode::updateOrCreate(
            ['type' => $type, 'slug' => $slug],
            ['label' => $labelEn, 'sort_order' => $sortOrder, 'meta' => $meta],
        );

        $node->translations()->updateOrCreate(
            ['translatable_type' => TaxonomyNode::class, 'translatable_id' => $node->id, 'field' => 'label', 'locale' => 'sr'],
            ['value' => $labelSr, 'source_hash' => hash('crc32', $labelEn), 'status' => 'human'],
        );

        return $node;
    }

    private function seedTripTypes(): void
    {
        $items = [
            ['slug' => 'city_break', 'en' => 'City break', 'sr' => 'City break'],
            ['slug' => 'snow', 'en' => 'Snow', 'sr' => 'Zimovanje / sneg'],
            ['slug' => 'summer_sea', 'en' => 'Summer / sea', 'sr' => 'Letovanje / more'],
        ];

        foreach ($items as $i => $item) {
            $this->node('trip_type', $item['slug'], $item['en'], $item['sr'], $i);
        }
    }

    private function seedGroupTypes(): void
    {
        $items = [
            ['slug' => 'porodica', 'en' => 'Family', 'sr' => 'Porodica'],
            ['slug' => 'skola', 'en' => 'School trip', 'sr' => 'Školski put'],
            ['slug' => 'drustvo_penzionera', 'en' => 'Retirees group', 'sr' => 'Društvo penzionera'],
            ['slug' => 'grupa_prijatelja', 'en' => 'Larger group of friends', 'sr' => 'Veća grupa prijatelja'],
            // Relabeled to a plain "Other" catch-all, 2026-08-13 (owner's call, from the
            // Club/sports-team-needs-real-research discussion) — "Club / sports team" implied
            // we had something specific figured out for it, which we deliberately don't yet
            // (parked for a v2 idea file: Spa? Team building? Corporate tourism?). Honest label
            // beats a specific-sounding one with no real handling behind it. Ordered last
            // (owner's call, 2026-08-13): a generic catch-all belongs at the end of the list.
            ['slug' => 'klub', 'en' => 'Other', 'sr' => 'Ostalo'],
        ];

        foreach ($items as $i => $item) {
            $this->node('group_type', $item['slug'], $item['en'], $item['sr'], $i);
        }
    }

    private function seedPersonas(): void
    {
        $items = [
            ['slug' => 'istrazivac', 'en' => 'Explorer', 'sr' => 'Istraživač'],
            // "Partygoer" -> "Party animal" (owner's catch, 2026-08-14: "nikad nisam čuo izraz,
            // boo mi oči") — matches the same playful, idiomatic register as the other personas
            // (Explorer, Foodie, Chillseeker), not a stiffer/more journalistic word.
            ['slug' => 'partijaner', 'en' => 'Party animal', 'sr' => 'Partijaner'],
            // Relations (Gurman/Foodie implies dobra_hrana) are wired up in seedRelations()
            // now that they live in the taxonomy_node_relations table, not in meta —
            // see wizard_architecture / admin-editability decision.
            ['slug' => 'gurman', 'en' => 'Foodie', 'sr' => 'Gurman'],
            ['slug' => 'flegma', 'en' => 'Chillseeker — just to relax', 'sr' => 'Flegma — samo da se opusti'],
        ];

        foreach ($items as $i => $item) {
            $this->node('persona', $item['slug'], $item['en'], $item['sr'], $i);
        }
    }

    /**
     * Only asked for exactly 2 adults + 0 children (see wizard.service.ts isQuestionVisible) —
     * owner's explicit call, 2026-07-30: persona itself stays universal/overlapping across
     * group sizes, but "just friends or more" is a genuinely separate signal for the 2-person
     * case specifically. "rodbina" included per owner's own parenthetical ("ili neki tip
     * drugara (ili rodbine)") — two siblings/relatives traveling together isn't a couple, but
     * isn't quite "just friends" either.
     */
    private function seedRelationshipType(): void
    {
        $items = [
            ['slug' => 'par', 'en' => 'Couple', 'sr' => 'Par'],
            ['slug' => 'drugari', 'en' => 'Friends', 'sr' => 'Drugari'],
            ['slug' => 'rodbina', 'en' => 'Relatives', 'sr' => 'Rodbina'],
        ];

        foreach ($items as $i => $item) {
            $this->node('relationship_type', $item['slug'], $item['en'], $item['sr'], $i);
        }
    }

    /**
     * Owner's call, 2026-08-13 ("vecina korisnika su idioti") — split out from
     * meal_plan_preference specifically so "I'll cook myself" is its own clear, mandatory
     * question rather than one pill buried in a "want meals included?" checklist someone
     * self-catering might not think to check. Drives BudgetEstimationEngine's
     * eating_out/self_catering split (a 1:3.5 swing in disposable accommodation budget) — see
     * GeographyResolver::filterByBudget / SearchSessionQueryCompiler::budgetSignal. No real
     * Booking filter behind either slug — pure wizard-side budget logic, same category as
     * group_type/relationship_type.
     */
    /**
     * Redesigned 2026-08-14 (owner's catch, second pass) — three top-level options now, each
     * mapping to one of BudgetEstimationEngine's three real spending styles directly:
     * - jede_napolju (Local restaurants) -> pure eating_out budget path.
     * - u_smestaju (At the accommodation) -> reveals meal_plan_preference's hotel-tier picker
     *   (breakfast/half-board/full-board/all-inclusive — self-catering pulled back OUT of that
     *   list, see seedAmenities' $mealPlans, since it's its own top-level option again now).
     * - sam_se_snalazim (I'll organize myself / cook) -> pure self_catering budget path
     *   directly, no follow-up question (nothing to ask — there's no "tier" of self-catering).
     *
     * Why the earlier "At the accommodation" design (2026-08-14, first pass) bundling self-
     * catering INTO the hotel-tier list was wrong: owner's own catch — "kaže ješću tamo gde
     * odsedam... a tamo je i hotelski restoran i opcija kupiću kačkavalj i praviću sendviče" —
     * both technically happen "at the accommodation," but self-catering isn't a hotel AMENITY
     * the way a meal-plan tier is, so it read as an odd fit inside that list. Splitting it back
     * out as its own top-level choice also fixes a real budget-fit bug: the OLD 2-option design
     * meant an eating_out-only session (jede_napolju) still silently fell back to a self_catering
     * fit when eating_out didn't fit some country's budget — showing "Fits if you cook for
     * yourself" to someone who explicitly said they'd eat at restaurants. With 3 clean options,
     * BudgetEstimationEngine::fitFor() can gate the fallback by the ACTUAL stated style instead
     * of guessing (see its updated docblock).
     */
    private function seedMealStyles(): void
    {
        $items = [
            ['slug' => 'jede_napolju', 'en' => 'Local restaurants', 'sr' => 'Lokalni restorani'],
            ['slug' => 'u_smestaju', 'en' => 'At the accommodation', 'sr' => 'U okviru smeštaja'],
            // Carries the real Booking `mealplan=999` (Self catering) filter ID directly, same
            // as it did on the old separate 'samostalno_kuvanje' meal_plan node.
            ['slug' => 'sam_se_snalazim', 'en' => "I'll organize myself (cook)", 'sr' => 'Sam ću da se snalazim (spremam)', 'meta' => ['booking_meal_plan_id' => 999]],
        ];

        foreach ($items as $i => $item) {
            $this->node('meal_style', $item['slug'], $item['en'], $item['sr'], $i, $item['meta'] ?? []);
        }
    }

    private function seedTerminCategories(): void
    {
        $items = [
            ['slug' => 'letovanje', 'en' => 'Summer holiday', 'sr' => 'Letovanje', 'meta' => ['date_tag' => 'summer', 'default_duration_days' => 7]],
            ['slug' => 'zimovanje', 'en' => 'Winter holiday', 'sr' => 'Zimovanje', 'meta' => ['date_tag' => 'winter', 'default_duration_days' => 7]],
            ['slug' => 'praznici', 'en' => 'For the holidays', 'sr' => 'Za praznike', 'meta' => ['date_tag' => 'holiday', 'default_duration_days' => 4]],
            ['slug' => 'vikend_break', 'en' => 'Weekend break', 'sr' => 'Vikend break', 'meta' => ['date_tag' => 'any', 'default_duration_days' => 2]],
            ['slug' => 'sledeca_nedelja', 'en' => 'Next week', 'sr' => 'Sledeća nedelja', 'meta' => ['date_tag' => 'any', 'default_duration_days' => 3]],
            ['slug' => 'sledeci_mesec', 'en' => 'Next month', 'sr' => 'Sledeći mesec', 'meta' => ['date_tag' => 'any', 'default_duration_days' => 3]],
            ['slug' => 'sledeca_sezona', 'en' => 'Next season', 'sr' => 'Sledeća sezona', 'meta' => ['date_tag' => 'any', 'default_duration_days' => 3]],
            ['slug' => 'znam_tacno_datum', 'en' => 'I know the exact date!', 'sr' => 'Znam tačno datum!', 'meta' => ['date_tag' => 'exact']],
            // First themed entry point (2026-07-14, see wizard_architecture "Session close-out")
            // — deliberately its own termin_category, not a variant of 'letovanje', since the
            // whole point is a DIFFERENT time window (Oct-Dec) with a DIFFERENT curated
            // geography set (see the excludes wired in seedRelations()).
            //
            // window_start/window_end/recommended_days_from_start (2026-07-30, see
            // wizard_architecture "SearchSessionQueryCompiler") drive the recommended-dates
            // fallback when a session picks this theme without exact dates — Booking always
            // needs real checkin/checkout to search at all, so "flexible" still resolves to a
            // concrete date, just a system-suggested one (editable by the user later, including
            // directly on Booking's own page once they get there).
            //
            // honest_report_thresholds is a generic, admin-editable comparator: each key must
            // match a real `taxonomy_node_climates` column name, so SearchSessionQueryCompiler
            // can evaluate new metrics later purely as data (no new PHP branch needed) — see
            // wizard_architecture for the owner's explicit "svaki kustomabilni, dopisivi" steer.
            [
                'slug' => 'kasno_kupanje', 'en' => 'One more week of sun', 'sr' => 'Još malo sunca',
                'meta' => [
                    'date_tag' => 'late_swim',
                    // Owner's call, 2026-08-05: Sept 19 -> Sept 27 specifically to bridge both
                    // weekends either side of the trip ("da spojimo vikende") — 8 nights, not
                    // an arbitrary round number.
                    'default_duration_days' => 8,
                    'window_start' => '09-19',
                    'window_end' => '11-05',
                    'recommended_days_from_start' => 7,
                    'honest_report_thresholds' => [
                        'sea_temp_c' => ['good' => 22, 'caveat' => 18],
                    ],
                ],
            ],
        ];

        foreach ($items as $i => $item) {
            $this->node('termin_category', $item['slug'], $item['en'], $item['sr'], $i, $item['meta']);
        }
    }

    private function seedPreferenceTags(): void
    {
        $items = [
            // 'kisa' ("Rain doesn't bother me") removed 2026-08-04 — a mismatch for this
            // sun/swim campaign specifically (owner's call). Only ONE campaign exists in the
            // whole system today, so a plain removal is simpler than building per-campaign tag
            // filtering for a node nothing else references yet — trivial to re-add if a future
            // (e.g. city-break) campaign genuinely wants it back.
            //
            // 'sunce' ("Must have sun") removed same way, 2026-08-13 (owner's call) — it was a
            // genuine orphan (zero geography data anywhere, unlike every other tag here) AND
            // redundant for a swim campaign specifically ("idu na more" — sun is already the
            // whole premise). Real idea for later, NOT this campaign: a future city-break
            // campaign (e.g. November city trips) could pull real % sunny-days per city from
            // Open-Meteo for that period and use it to differentiate "cloudy but full of
            // pubs/history" cities from "still sunny" ones (Athens/Lisbon/Rome) — parked, see
            // project memory.
            ['slug' => 'jeftino', 'en' => 'Cheap', 'sr' => 'Jeftino'],
            ['slug' => 'kvalitet', 'en' => 'Quality over price', 'sr' => 'Kvalitet pre cene'],
            ['slug' => 'pivo', 'en' => 'Good beer', 'sr' => 'Dobro pivo'],
            ['slug' => 'vino', 'en' => 'Good wine', 'sr' => 'Dobro vino'],
            ['slug' => 'dobra_hrana', 'en' => 'Great food', 'sr' => 'Odlična hrana'],
            // Owner's ask, 2026-08-21 — split from the general "Great food" axis: a real
            // coffee-vs-tea distinction that lands differently by nationality (an Italian
            // traveler cares about espresso quality, an English one about tea availability).
            // Same `drinks` meta key as pivo/vino, deliberately NOT auto-propagated everywhere
            // like those two (see propagateCountryDrinksToCities) — this is a curated national
            // REPUTATION claim (same spirit as dobra_hrana), not blanket "sold in every corner
            // store" availability.
            ['slug' => 'kafa', 'en' => 'Coffee Culture', 'sr' => 'Kultura kafe'],
            ['slug' => 'caj', 'en' => 'Tea Culture', 'sr' => 'Kultura čaja'],
            // Genuine atmosphere/vibe axis, added 2026-08-04 alongside the question's relabel
            // to "Atmosphere / Vibe of this trip" — deliberately distinct from persona (these
            // describe the PLACE's mood, persona describes the TRAVELER).
            ['slug' => 'zivahna_nocna_zabava', 'en' => 'Lively nightlife', 'sr' => 'Živahna noćna zabava'],
            ['slug' => 'mirno_i_tiho', 'en' => 'Peaceful & quiet', 'sr' => 'Mirno i tiho'],
            ['slug' => 'van_utabanih_staza', 'en' => 'Off the beaten path', 'sr' => 'Van utabanih staza'],
            ['slug' => 'porodicna_atmosfera', 'en' => 'Family-friendly atmosphere', 'sr' => 'Porodična atmosfera'],
            // Owner's ask, 2026-08-12: "nekima nije samo pesak i uso u vodu" — a distinct axis
            // from van_utabanih_staza (that's about the DESTINATION overall; this is purely
            // about the beach itself). See seedExplorationAndBeachTags().
            ['slug' => 'lepe_plaze', 'en' => 'Great beaches', 'sr' => 'Lepe plaže'],
            // Owner's ask, 2026-08-13 ("za Couple nemamo ni jedan romanticarski index") —
            // relationship_type=par (Couple) suggests this. Deliberately no city/country tagging
            // pass for it yet (unlike food/wine/beach/exploration) — parked until real content
            // decisions are made about which destinations actually earn it.
            ['slug' => 'romanticno', 'en' => 'Romantic atmosphere', 'sr' => 'Romantična atmosfera'],
            // Cultural-availability preference tags (2026-07-30, see wizard_architecture
            // "cultural_availability engine"). `meta.cultural_category` + `meta.max_tier` is
            // the generic convention GeographyResolver reads — same shape as budget_tier's
            // meta.min/max — so a new cultural ask later is a seed row, not a resolver change.
            // Selecting one means "I need this at tier <= max_tier or better" (1=most free).
            ['slug' => 'zeli_alkohol_slobodno', 'en' => 'Want easy access to alcohol', 'sr' => 'Slobodan pristup alkoholu', 'meta' => ['cultural_category' => 'alcohol', 'max_tier' => 2]],
            ['slug' => 'zeli_halal', 'en' => 'Want halal food options', 'sr' => 'Halal opcije', 'meta' => ['cultural_category' => 'halal', 'max_tier' => 2]],
            ['slug' => 'zeli_vegan', 'en' => 'Want vegan food options', 'sr' => 'Vegan opcije', 'meta' => ['cultural_category' => 'vegan', 'max_tier' => 2]],
            ['slug' => 'zeli_lgbt_friendly', 'en' => 'Want LGBT-friendly destination', 'sr' => 'LGBT prijateljska destinacija', 'meta' => ['cultural_category' => 'lgbtq_friendly', 'max_tier' => 2]],
        ];

        foreach ($items as $i => $item) {
            $this->node('preference_tag', $item['slug'], $item['en'], $item['sr'], $i, $item['meta'] ?? null);
        }

        // German isn't auto-translated (see TranslateDirective's docblock — no live AI-trigger
        // pipeline, Claude translates on request) — added directly here since DE is currently
        // the only live market (see CLAUDE.md §7 market-expansion-sequence memory).
        $germanLabels = ['kafa' => 'Kaffeekultur', 'caj' => 'Teekultur'];
        foreach ($germanLabels as $slug => $labelDe) {
            $node = TaxonomyNode::where('type', 'preference_tag')->where('slug', $slug)->first();
            $node->translations()->updateOrCreate(
                ['translatable_type' => TaxonomyNode::class, 'translatable_id' => $node->id, 'field' => 'label', 'locale' => 'de'],
                ['value' => $labelDe, 'source_hash' => hash('crc32', $node->label), 'status' => 'human'],
            );
        }
    }

    /**
     * Discrete tiers (not a raw number input) so budget can participate in the same
     * implies/suggests/excludes system as everything else. min/max map straight onto
     * Booking's filters.price.minimum/maximum once the real query engine exists.
     */
    private function seedBudgetTiers(): void
    {
        $items = [
            ['slug' => 'do_20e', 'en' => 'Up to 20€/night', 'sr' => 'Do 20€/noć', 'meta' => ['min' => 0, 'max' => 20, 'currency' => 'EUR']],
            ['slug' => '20_50e', 'en' => '20-50€/night', 'sr' => '20-50€/noć', 'meta' => ['min' => 20, 'max' => 50, 'currency' => 'EUR']],
            ['slug' => '50_100e', 'en' => '50-100€/night', 'sr' => '50-100€/noć', 'meta' => ['min' => 50, 'max' => 100, 'currency' => 'EUR']],
            ['slug' => '100e_plus', 'en' => '100€+/night', 'sr' => '100€+/noć', 'meta' => ['min' => 100, 'max' => null, 'currency' => 'EUR']],
        ];

        foreach ($items as $i => $item) {
            $this->node('budget_tier', $item['slug'], $item['en'], $item['sr'], $i, $item['meta']);
        }
    }

    /**
     * Real Booking.com filter IDs, 2026-08-03 — owner observed these directly on the public
     * website's filter sidebar (checkbox `name`/`value` attributes, e.g. `hotelfacility=433`),
     * NOT the authenticated Partner Demand API's `/accommodations/constants` endpoint (still
     * blocked on affiliate access). Numbering COULD differ between the two systems — marked
     * `source: 'manual_website'` (distinct from `manual_test`/`manual_estimate` elsewhere in
     * this seeder) specifically so this is easy to find and re-verify once real API access
     * exists. `tip_smestaja` was planned since 2026-07-11 and never seeded until now — this
     * finally unblocks `SearchSessionQueryCompiler::toBookingParams()`'s
     * `filters.accommodation_types`, which has been silently absent from every real session.
     */
    private function seedAmenities(): void
    {
        // ht_id — property type. Same taxonomy type (`tip_smestaja`) and meta key
        // (`booking_accommodation_type_ids`) already adopted in wizard_architecture — an
        // array because a real Booking accommodation_type can map to more than one ht_id
        // (see the migration-guide note on hotel/aparthotel overlap); one-element arrays here
        // since we only have single observed IDs so far.
        $tipSmestaja = [
            ['slug' => 'hotel', 'en' => 'Hotel', 'sr' => 'Hotel', 'id' => 204],
            ['slug' => 'apartman', 'en' => 'Apartment', 'sr' => 'Apartman', 'id' => 201],
            ['slug' => 'vila', 'en' => 'Villa', 'sr' => 'Vila', 'id' => 213],
            ['slug' => 'holiday_home', 'en' => 'Holiday home', 'sr' => 'Kuća za odmor', 'id' => 220],
            ['slug' => 'guest_house', 'en' => 'Guest house', 'sr' => 'Gostinska kuća', 'id' => 216],
            ['slug' => 'chalet', 'en' => 'Chalet', 'sr' => 'Šale', 'id' => 228],
        ];
        foreach ($tipSmestaja as $i => $item) {
            $this->node('tip_smestaja', $item['slug'], $item['en'], $item['sr'], $i, [
                'booking_accommodation_type_ids' => [$item['id']],
                'source' => 'manual_website',
            ]);
        }

        // hotelfacility — property-level amenities (filters.accommodation_facilities).
        $accommodationFacilities = [
            ['slug' => 'bazen', 'en' => 'Swimming pool', 'sr' => 'Bazen', 'id' => 433],
            ['slug' => 'plaza', 'en' => 'Beachfront', 'sr' => 'Na plaži', 'id' => 146],
            ['slug' => 'parking', 'en' => 'Parking', 'sr' => 'Parking', 'id' => 2],
            ['slug' => 'wifi', 'en' => 'Free WiFi', 'sr' => 'Besplatan WiFi', 'id' => 107],
            ['slug' => 'spa', 'en' => 'Spa & wellness centre', 'sr' => 'Spa i wellness', 'id' => 54],
            // Added 2026-08-13 from owner's own real "Facilities" filter-sidebar export —
            // curated to what's actually relevant for a family/couple beach trip (dropped
            // niche ones like EV charging from the same export).
            ['slug' => 'restoran', 'en' => 'Restaurant', 'sr' => 'Restoran', 'id' => 3],
            ['slug' => 'usluga_u_sobu', 'en' => 'Room service', 'sr' => 'Usluga u sobu', 'id' => 5],
            ['slug' => 'recepcija_24h', 'en' => '24-hour front desk', 'sr' => 'Recepcija 24h', 'id' => 8],
            ['slug' => 'teretana', 'en' => 'Fitness centre', 'sr' => 'Teretana', 'id' => 11],
            ['slug' => 'sobe_za_nepusace', 'en' => 'Non-smoking rooms', 'sr' => 'Sobe za nepušače', 'id' => 16],
            ['slug' => 'aerodromski_prevoz', 'en' => 'Airport shuttle', 'sr' => 'Prevoz od aerodroma', 'id' => 17],
            ['slug' => 'djakuzi', 'en' => 'Hot tub/Jacuzzi', 'sr' => 'Džakuzi', 'id' => 63],
            ['slug' => 'pristupacnost_kolica', 'en' => 'Wheelchair accessible', 'sr' => 'Pristupačno kolicima', 'id' => 185],
        ];
        foreach ($accommodationFacilities as $i => $item) {
            $this->node('accommodation_facility', $item['slug'], $item['en'], $item['sr'], $i, [
                'booking_facility_id' => $item['id'],
                'source' => 'manual_website',
            ]);
        }

        // roomfacility — room-level amenities (filters.room_facilities).
        $roomFacilities = [
            ['slug' => 'klima', 'en' => 'Air conditioning', 'sr' => 'Klima', 'id' => 11],
            ['slug' => 'privatno_kupatilo', 'en' => 'Private bathroom', 'sr' => 'Privatno kupatilo', 'id' => 38],
            ['slug' => 'privatni_bazen', 'en' => 'Private pool', 'sr' => 'Privatni bazen', 'id' => 93],
            ['slug' => 'pogled_na_more', 'en' => 'Sea view', 'sr' => 'Pogled na more', 'id' => 108],
            ['slug' => 'balkon', 'en' => 'Balcony', 'sr' => 'Balkon', 'id' => 17],
            // Added 2026-08-13 from owner's own real "Room facilities" filter-sidebar export —
            // curated (dropped niche ones from the same export: Fax, Game console, Reading
            // light, Privacy curtain, Pool cover, Yukata, room-level Hot tub — already have the
            // property-level Jacuzzi in accommodation_facility, a second near-duplicate would
            // just be confusing pill choice).
            ['slug' => 'kuhinja', 'en' => 'Kitchen/kitchenette', 'sr' => 'Kuhinja', 'id' => 999],
            ['slug' => 'vesmasina', 'en' => 'Washing machine', 'sr' => 'Veš mašina', 'id' => 34],
            ['slug' => 'frizider', 'en' => 'Refrigerator', 'sr' => 'Frižider', 'id' => 22],
            ['slug' => 'terasa', 'en' => 'Terrace', 'sr' => 'Terasa', 'id' => 123],
        ];
        foreach ($roomFacilities as $i => $item) {
            $this->node('room_facility', $item['slug'], $item['en'], $item['sr'], $i, [
                'booking_facility_id' => $item['id'],
                'source' => 'manual_website',
            ]);
        }

        // mealplan — filters.meal_plan (verified real 2026-07-30; expanded 2026-08-13 with the
        // rest of Booking's real "Meals" filter group, owner's own export — all_inclusive/
        // pun_pansion IDs were previously left out rather than guessed, now confirmed real).
        // 'Self catering' does NOT live here, 2026-08-14 (owner's second catch) — it's the
        // 'sam_se_snalazim' meal_style node instead (see seedMealStyles), its own top-level
        // choice rather than one pill inside this hotel-tier list — self-catering isn't a hotel
        // amenity the way these are, it just happened to physically occur at the accommodation
        // too, which read as a confusing fit alongside real meal-plan tiers.
        $mealPlans = [
            ['slug' => 'dorucak', 'en' => 'Breakfast included', 'sr' => 'Doručak uključen', 'id' => 1],
            ['slug' => 'dorucak_rucak', 'en' => 'Breakfast & lunch included', 'sr' => 'Doručak i ručak uključeni', 'id' => 8],
            ['slug' => 'dorucak_vecera', 'en' => 'Breakfast & dinner included', 'sr' => 'Doručak i večera uključeni', 'id' => 9],
            ['slug' => 'pun_pansion', 'en' => 'All meals included', 'sr' => 'Svi obroci uključeni', 'id' => 3],
            ['slug' => 'sve_ukljuceno', 'en' => 'All-inclusive', 'sr' => 'Sve uključeno', 'id' => 4],
        ];
        foreach ($mealPlans as $i => $item) {
            $this->node('meal_plan', $item['slug'], $item['en'], $item['sr'], $i, [
                'booking_meal_plan_id' => $item['id'],
                'source' => 'manual_website',
            ]);
        }
    }

    private function seedGeography(): void
    {
        // Region theme nodes — thematic groupings, root level, tag-matched against trip_type
        // via taxonomy_node_relations (see seedRelations), not meta tags.
        $themes = [
            'istocna_evropa' => ['en' => 'Eastern Europe', 'sr' => 'Istočna Evropa'],
            'zapadna_evropa' => ['en' => 'Western Europe', 'sr' => 'Zapadna Evropa'],
            'anticki_svet' => ['en' => 'Ancient world', 'sr' => 'Antički svet'],
        ];

        $themeNodes = [];
        $i = 0;
        foreach ($themes as $slug => $theme) {
            $themeNodes[$slug] = $this->node('region_theme', $slug, $theme['en'], $theme['sr'], $i++);
        }

        // Countries — children of region_theme, tag-matched further by atmosphere/drinks/food/budget.
        $countries = [
            'ceska' => [
                'en' => 'Czech Republic', 'sr' => 'Češka', 'parent' => 'istocna_evropa',
                'meta' => ['best_seasons' => ['spring', 'summer', 'autumn'], 'atmosphere' => ['istorijski', 'zivahno'], 'drinks' => ['pivo']],
            ],
            'belgija' => [
                'en' => 'Belgium', 'sr' => 'Belgija', 'parent' => 'zapadna_evropa',
                'meta' => ['best_seasons' => ['spring', 'summer'], 'atmosphere' => ['romanticno', 'mirno'], 'drinks' => ['pivo']],
            ],
            'italija' => [
                'en' => 'Italy', 'sr' => 'Italija', 'parent' => 'anticki_svet',
                'meta' => ['best_seasons' => ['spring', 'autumn'], 'atmosphere' => ['istorijski', 'kulturno'], 'drinks' => ['vino'], 'food' => ['dobra_hrana']],
            ],
            'grcka' => [
                'en' => 'Greece', 'sr' => 'Grčka', 'parent' => 'anticki_svet',
                'meta' => ['best_seasons' => ['summer'], 'atmosphere' => ['opusteno', 'anticko'], 'food' => ['dobra_hrana']],
            ],
            // Not a destination theme fit (no cuisine/atmosphere tags) — exists purely to carry
            // the home_city_id example (Beograd) for the distance-from-home mechanism.
            'srbija' => [
                'en' => 'Serbia', 'sr' => 'Srbija', 'parent' => 'istocna_evropa',
                'meta' => [],
            ],
        ];

        $countryNodes = [];
        $i = 0;
        foreach ($countries as $slug => $country) {
            $node = TaxonomyNode::updateOrCreate(
                ['type' => 'country', 'slug' => $slug],
                [
                    'label' => $country['en'],
                    'parent_id' => $themeNodes[$country['parent']]->id,
                    'sort_order' => $i++,
                    'meta' => $country['meta'],
                ],
            );
            $node->translations()->updateOrCreate(
                ['translatable_type' => TaxonomyNode::class, 'translatable_id' => $node->id, 'field' => 'label', 'locale' => 'sr'],
                ['value' => $country['sr'], 'source_hash' => hash('crc32', $country['en']), 'status' => 'human'],
            );
            $countryNodes[$slug] = $node;
        }

        // Cities — children of country, carry their own atmosphere/drinks/food tags.
        // lat/lng (convention, not a schema column — see TaxonomyNode::distanceKmTo) power the
        // distance-from-home mechanism; approximate city-center coordinates, precision doesn't
        // matter for a "how far is this roughly" figure.
        $cities = [
            'prag' => ['en' => 'Prague', 'sr' => 'Prag', 'parent' => 'ceska', 'meta' => ['atmosphere' => ['istorijski', 'nocni_zivot'], 'drinks' => ['pivo'], 'budget' => ['jeftino'], 'lat' => 50.0755, 'lng' => 14.4378]],
            'brugge' => ['en' => 'Bruges', 'sr' => 'Brugge', 'parent' => 'belgija', 'meta' => ['atmosphere' => ['romanticno', 'kanali'], 'drinks' => ['pivo'], 'budget' => ['kvalitet'], 'lat' => 51.2093, 'lng' => 3.2247]],
            'rim' => ['en' => 'Rome', 'sr' => 'Rim', 'parent' => 'italija', 'meta' => ['atmosphere' => ['istorijski', 'anticko'], 'food' => ['dobra_hrana'], 'lat' => 41.9028, 'lng' => 12.4964]],
            // Cost-of-living fields here are one manual proof example, not real researched
            // numbers — the actual data-fill (WhereNext + friends) is separate future work,
            // see project_dev_phases. `priced_at` lets a future refresh job find stale entries.
            'atina' => ['en' => 'Athens', 'sr' => 'Atina', 'parent' => 'grcka', 'meta' => [
                'atmosphere' => ['anticko', 'istorijski'], 'food' => ['dobra_hrana'], 'lat' => 37.9838, 'lng' => 23.7275,
                'hospitality' => ['avg_restaurant_meal_eur' => 15, 'avg_cafe_coffee_eur' => 2.5, 'avg_bar_beer_eur' => 3.5, 'priced_at' => '2026-07-13', 'source' => 'manual_estimate'],
                'local_stores' => ['avg_store_beer_eur' => 1.2, 'avg_meat_price_eur_kg' => 9, 'avg_cigarettes_pack_eur' => 5, 'priced_at' => '2026-07-13', 'source' => 'manual_estimate'],
                'transport' => ['avg_public_transport_ticket_eur' => 1.4, 'priced_at' => '2026-07-13', 'source' => 'manual_estimate'],
            ]],
            // Home-city example, not a destination — see 'srbija' country above.
            'beograd' => ['en' => 'Belgrade', 'sr' => 'Beograd', 'parent' => 'srbija', 'meta' => ['lat' => 44.7866, 'lng' => 20.4489]],
        ];

        foreach ($cities as $slug => $city) {
            $node = TaxonomyNode::updateOrCreate(
                ['type' => 'city', 'slug' => $slug],
                [
                    'label' => $city['en'],
                    'parent_id' => $countryNodes[$city['parent']]->id,
                    'meta' => $city['meta'],
                ],
            );
            $node->translations()->updateOrCreate(
                ['translatable_type' => TaxonomyNode::class, 'translatable_id' => $node->id, 'field' => 'label', 'locale' => 'sr'],
                ['value' => $city['sr'], 'source_hash' => hash('crc32', $city['en']), 'status' => 'human'],
            );
        }
    }

    /**
     * cost_category nodes — what a traveler's choice can weigh toward, see the
     * `weighted_toward` relation in seedRelations() and TaxonomyNode::weightedToward().
     * `transport` is seeded now per owner's explicit call even though no destination carries
     * transport cost meta yet — content follows once a real source (WhereNext) is wired up.
     */
    private function seedCostCategories(): void
    {
        $items = [
            ['slug' => 'hospitality', 'en' => 'Hospitality (eating/drinking out)', 'sr' => 'Ugostiteljstvo'],
            ['slug' => 'local_stores', 'en' => 'Local stores (self-catering)', 'sr' => 'Lokalne prodavnice'],
            ['slug' => 'transport', 'en' => 'Local transport', 'sr' => 'Lokalni prevoz'],
        ];

        foreach ($items as $i => $item) {
            $this->node('cost_category', $item['slug'], $item['en'], $item['sr'], $i);
        }
    }

    /**
     * Monthly climate rows (temp/rain/sun/snow) for the four existing destination cities —
     * plausible general-knowledge approximations to prove the mechanism and give the admin
     * something real to look at, NOT researched figures (same "manual_estimate" convention as
     * the cost-of-living data). Real sourcing (Open-Meteo/NOAA/Wikipedia climate tables or
     * similar) is separate future work. Format per month: [temp_c, precip_mm, sun_hours, snow_cm].
     */
    private function seedClimate(): void
    {
        $data = [
            'prag' => [
                1 => [0, 25, 45, 5], 2 => [2, 24, 70, 4], 3 => [6, 30, 120, 1], 4 => [11, 33, 165, 0],
                5 => [16, 62, 200, 0], 6 => [19, 68, 210, 0], 7 => [20, 72, 220, 0], 8 => [20, 68, 210, 0],
                9 => [15, 40, 160, 0], 10 => [10, 32, 110, 0], 11 => [5, 28, 55, 1], 12 => [1, 25, 35, 4],
            ],
            'brugge' => [
                1 => [4, 70, 55, 1], 2 => [4, 55, 80, 1], 3 => [7, 55, 115, 0], 4 => [10, 45, 165, 0],
                5 => [13, 55, 190, 0], 6 => [16, 65, 190, 0], 7 => [18, 70, 185, 0], 8 => [18, 75, 180, 0],
                9 => [15, 65, 140, 0], 10 => [12, 80, 100, 0], 11 => [7, 80, 60, 0], 12 => [5, 80, 45, 0],
            ],
            'rim' => [
                1 => [8, 68, 115, 0], 2 => [9, 63, 130, 0], 3 => [11, 57, 165, 0], 4 => [14, 55, 200, 0],
                5 => [18, 47, 245, 0], 6 => [22, 32, 280, 0], 7 => [25, 15, 310, 0], 8 => [25, 25, 285, 0],
                9 => [22, 63, 220, 0], 10 => [17, 95, 165, 0], 11 => [13, 100, 115, 0], 12 => [9, 80, 100, 0],
            ],
            'atina' => [
                1 => [10, 55, 130, 0], 2 => [11, 46, 140, 0], 3 => [13, 40, 180, 0], 4 => [17, 24, 230, 0],
                5 => [21, 18, 280, 0], 6 => [26, 10, 320, 0], 7 => [29, 6, 350, 0], 8 => [29, 7, 330, 0],
                9 => [25, 15, 260, 0], 10 => [20, 48, 190, 0], 11 => [16, 55, 140, 0], 12 => [12, 60, 115, 0],
            ],
        ];

        foreach ($data as $slug => $months) {
            $node = TaxonomyNode::where('type', 'city')->where('slug', $slug)->firstOrFail();

            $rows = [];
            foreach ($months as $month => [$temp, $precip, $sun, $snow]) {
                $rows[] = [
                    'taxonomy_node_id' => $node->id,
                    'month' => $month,
                    'avg_temp_c' => $temp,
                    'precip_mm' => $precip,
                    'sun_hours' => $sun,
                    'snow_cm' => $snow === 0 ? null : $snow,
                    'source' => 'manual_estimate',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            TaxonomyNodeClimate::where('taxonomy_node_id', $node->id)->delete();
            TaxonomyNodeClimate::insert($rows);
        }
    }

    /**
     * Fake-ID stand-ins for Booking.com's own location catalog (see Location model + the
     * create_locations_table migration) — `booking_dest_id` values are made up ("test_..."),
     * not real Booking IDs, since we don't have API/affiliate access yet. Swapping these for
     * real dest_ids later is a data update, not a schema change — that's the whole point of
     * keeping `locations` separate from `taxonomy_nodes`.
     */
    private function seedLocations(): void
    {
        $items = [
            'prag' => ['dest_id' => 'test_prag_city', 'name' => 'Prague', 'country_code' => 'CZ', 'nr_hotels' => 850],
            'brugge' => ['dest_id' => 'test_brugge_city', 'name' => 'Bruges', 'country_code' => 'BE', 'nr_hotels' => 210],
            'rim' => ['dest_id' => 'test_rim_city', 'name' => 'Rome', 'country_code' => 'IT', 'nr_hotels' => 2400],
            'atina' => ['dest_id' => 'test_atina_city', 'name' => 'Athens', 'country_code' => 'GR', 'nr_hotels' => 1100],
        ];

        foreach ($items as $slug => $item) {
            $location = Location::updateOrCreate(
                ['booking_dest_id' => $item['dest_id']],
                [
                    'dest_type' => 'city',
                    'name' => $item['name'],
                    'country_code' => $item['country_code'],
                    'nr_hotels' => $item['nr_hotels'],
                    'source' => 'manual_test',
                ],
            );

            TaxonomyNode::where('type', 'city')->where('slug', $slug)
                ->update(['booking_location_id' => $location->id]);
        }
    }

    /**
     * Late-season-swim demo content (2026-07-13) — "sve ponuđeno" from the Oct/Nov warm-water
     * research (see wizard_architecture), broadened from one city per country to several real
     * resort towns per country, so the demo has enough volume to look like a real product for
     * the Booking affiliate application. Deliberately test-quality, not final content:
     * - `on_sea`/`has_beach` are set true uniformly for this whole batch (they were all
     *   selected specifically as coastal resort towns) — the real per-town precision (owner's
     *   own example: only ~5 spots on Malta have an actual beach despite the whole island being
     *   "on the sea") is owner's explicit future refinement, not done here.
     * - Climate is seeded for October/November/December ONLY (air + sea temp), not the full 12
     *   months — that's the actual window this theme cares about; full-year climate for this
     *   batch is intentionally deferred, not silently missing.
     * - No implies/suggests/excludes/seasonal_window relations are wired for any of these yet —
     *   that's curation judgment (which city fits which persona), left for a follow-up pass.
     * - Numbers are plausible approximations (cross-checked against real research for Egypt/
     *   Cyprus/Canaries/Malta/Tunisia/Turkey specifically), not authoritative — same
     *   "manual_estimate"/"manual_test" convention as the rest of this seeder.
     */
    private function seedSwimDestinations(): void
    {
        $mediteran = $this->node('region_theme', 'mediteran', 'Mediterranean', 'Mediteran', 3);

        $countries = [
            'egipat' => ['en' => 'Egypt', 'sr' => 'Egipat', 'iso' => 'EG'],
            'kipar' => ['en' => 'Cyprus', 'sr' => 'Kipar', 'iso' => 'CY'],
            'malta' => ['en' => 'Malta', 'sr' => 'Malta', 'iso' => 'MT'],
            'tunis' => ['en' => 'Tunisia', 'sr' => 'Tunis', 'iso' => 'TN'],
            'spanija' => ['en' => 'Spain', 'sr' => 'Španija', 'iso' => 'ES'],
            'turska' => ['en' => 'Turkey', 'sr' => 'Turska', 'iso' => 'TR'],
            'portugalija' => ['en' => 'Portugal', 'sr' => 'Portugalija', 'iso' => 'PT'],
            // Croatia removed from this loop 2026-08-19 (owner's ask) — it was already the
            // weakest fit for a "still warm" late-season campaign (own vibe_profile comment
            // below: "coolest of the ten by late season") and never got real prices entered.
            // Deliberately NOT deleted, just detached from `mediteran` (see the one-off
            // parent_id fix run alongside this change) — everything else (climate, hospitality/
            // cultural meta, vibe_profile, DE translation, season template) stays untouched in
            // the DB, ready to reattach for a future non-swim campaign without redoing any of
            // it. Being absent from THIS array just means re-seeding no longer re-parents it
            // back under `mediteran`.
            // Added 2026-08-19 (owner's ask, riding real World Cup-qualification interest in
            // DACH — Cape Verde's own "Blue Sharks" made their first-ever World Cup). Genuine
            // fit regardless of the hook: real direct DACH charter routes (TUIfly Köln, Condor
            // München, Lufthansa Frankfurt -> Sal) confirmed via research, and it's a true
            // winter-sun destination (stays ~25°C when the Mediterranean has cooled off) —
            // exactly the "still warm" story this campaign already tells, just further out.
            'zelenortska_ostrva' => ['en' => 'Cape Verde', 'sr' => 'Zelenortska ostrva', 'iso' => 'CV'],
        ];

        $countryNodes = [];
        foreach ($countries as $slug => $c) {
            $countryNodes[$slug] = TaxonomyNode::updateOrCreate(
                ['type' => 'country', 'slug' => $slug],
                ['label' => $c['en'], 'parent_id' => $mediteran->id, 'sort_order' => 0],
            );
            $countryNodes[$slug]->translations()->updateOrCreate(
                ['translatable_type' => TaxonomyNode::class, 'translatable_id' => $countryNodes[$slug]->id, 'field' => 'label', 'locale' => 'sr'],
                ['value' => $c['sr'], 'source_hash' => hash('crc32', $c['en']), 'status' => 'human'],
            );
        }

        // Existing countries these new cities attach to (already seeded in seedGeography()).
        $countryNodes['grcka'] = TaxonomyNode::where('type', 'country')->where('slug', 'grcka')->firstOrFail();
        $countryNodes['italija'] = TaxonomyNode::where('type', 'country')->where('slug', 'italija')->firstOrFail();

        // slug => [en, sr, country slug, lat, lng, [month => [air_c, sea_c]]]
        $cities = [
            // Egypt — warmest option by far, sea stays swimmable well into December.
            'hurgada' => ['Hurghada', 'Hurgada', 'egipat', 27.2579, 33.8116, [10 => [32, 27.4], 11 => [27, 26], 12 => [23, 24]]],
            'sarm_el_seik' => ['Sharm El Sheikh', 'Šarm el Šeik', 'egipat', 27.9158, 34.3299, [10 => [32, 27], 11 => [28, 26], 12 => [24, 24.5]]],
            'marsa_alam' => ['Marsa Alam', 'Marsa Alam', 'egipat', 25.0757, 34.8917, [10 => [33, 27.5], 11 => [28, 26.5], 12 => [25, 25]]],
            // Expansion round, 2026-08-11 — placeholders below are same-latitude-neighbor
            // estimates (El Gouna/Safaga/Soma Bay ~ Hurghada; Dahab/Nuweiba/Taba ~ Sharm,
            // scaled down going north up the Gulf of Aqaba), overwritten by climate:import.
            'el_guna' => ['El Gouna', 'El Guna', 'egipat', 27.3950, 33.6773, [10 => [32, 27.4], 11 => [27, 26], 12 => [23, 24]]],
            'dahab' => ['Dahab', 'Dahab', 'egipat', 28.5091, 34.5136, [10 => [31, 26.5], 11 => [27, 25.5], 12 => [23, 24]]],
            'nuvejba' => ['Nuweiba', 'Nuvejba', 'egipat', 29.0333, 34.6667, [10 => [30.5, 26.2], 11 => [26, 25.2], 12 => [22, 23.5]]],
            'taba' => ['Taba', 'Taba', 'egipat', 29.4913, 34.8968, [10 => [30, 26], 11 => [25, 25], 12 => [21, 23]]],
            'safaga' => ['Safaga', 'Safaga', 'egipat', 26.7333, 33.9333, [10 => [32, 27.5], 11 => [27, 26], 12 => [23, 24.5]]],
            'soma_bej' => ['Soma Bay', 'Soma Bej', 'egipat', 26.8578, 33.9622, [10 => [32, 27.5], 11 => [27, 26], 12 => [23, 24.5]]],

            // Cyprus
            'larnaka' => ['Larnaca', 'Larnaka', 'kipar', 34.9167, 33.6333, [10 => [27, 25], 11 => [22, 22], 12 => [18, 19]]],
            'pafos' => ['Paphos', 'Pafos', 'kipar', 34.7761, 32.4245, [10 => [26, 24.5], 11 => [21, 21.5], 12 => [17, 19]]],
            'ajia_napa' => ['Ayia Napa', 'Ajia Napa', 'kipar', 34.9885, 34.0, [10 => [27, 25], 11 => [22, 22], 12 => [18, 19]]],

            // Malta — owner's "only ~5 spots have a real beach" example; has_beach still true
            // for all three here (they ARE the real beach spots), just noted for context.
            'melieha' => ['Mellieħa', 'Melieha', 'malta', 35.9556, 14.3617, [10 => [24, 24], 11 => [20, 21.5], 12 => [17, 18.5]]],
            'sliema' => ['Sliema', 'Sliema', 'malta', 35.9128, 14.5019, [10 => [24, 24], 11 => [20, 21.5], 12 => [17, 18.5]]],
            'st_julians' => ["St. Julian's", "Sent Džulijans", 'malta', 35.9186, 14.4881, [10 => [24, 24], 11 => [20, 21.5], 12 => [17, 18.5]]],

            // Tunisia
            'hamamet' => ['Hammamet', 'Hamamet', 'tunis', 36.4, 10.6167, [10 => [26, 24], 11 => [20, 21], 12 => [16, 18]]],
            'djerba' => ['Djerba', 'Đerba', 'tunis', 33.8076, 10.8451, [10 => [27, 24.5], 11 => [22, 21.5], 12 => [18, 19]]],
            'susa' => ['Sousse', 'Susa', 'tunis', 35.8256, 10.6411, [10 => [26, 24], 11 => [20, 21], 12 => [16, 18]]],
            'monastir' => ['Monastir', 'Monastir', 'tunis', 35.7643, 10.8113, [10 => [26, 24], 11 => [20, 21], 12 => [16, 18]]],
            // Expansion round, 2026-08-11 — placeholders scaled by latitude: Nabeul ~ Hammamet
            // (right next door); Mahdia/Sfax between Monastir and Djerba; Zarzis ~ Djerba;
            // Tabarka/Bizerte north of everything else, coolest in the country.
            'nabel' => ['Nabeul', 'Nabel', 'tunis', 36.4561, 10.7376, [10 => [26, 24], 11 => [20, 21], 12 => [16, 18]]],
            'mahdija' => ['Mahdia', 'Mahdija', 'tunis', 35.5047, 11.0622, [10 => [26.5, 24.2], 11 => [21, 21.2], 12 => [17, 18.5]]],
            'sfaks' => ['Sfax', 'Sfaks', 'tunis', 34.7406, 10.7603, [10 => [26.5, 24.3], 11 => [21, 21.3], 12 => [17, 18.7]]],
            'zarzis' => ['Zarzis', 'Zarzis', 'tunis', 33.5044, 11.1122, [10 => [27, 24.7], 11 => [22.5, 21.7], 12 => [18.5, 19.2]]],
            'tabarka' => ['Tabarka', 'Tabarka', 'tunis', 36.9500, 8.7500, [10 => [24, 23], 11 => [18, 19.5], 12 => [14, 16.5]]],
            'bizerta' => ['Bizerte', 'Bizerta', 'tunis', 37.2744, 9.8739, [10 => [23.5, 22.5], 11 => [17.5, 19], 12 => [13.5, 16]]],

            // Spain — Canary Islands, the one place warm nearly year-round, not just "late season".
            'tenerife' => ['Tenerife', 'Tenerife', 'spanija', 28.2916, -16.6291, [10 => [25, 23], 11 => [23, 22], 12 => [21, 21]]],
            'gran_kanarija' => ['Gran Canaria', 'Gran Kanarija', 'spanija', 28.1235, -15.4363, [10 => [25, 23], 11 => [23, 22], 12 => [21, 21]]],
            'lansarote' => ['Lanzarote', 'Lansarote', 'spanija', 28.9630, -13.5477, [10 => [24, 22.5], 11 => [22, 21.5], 12 => [20, 20.5]]],
            'fuerteventura' => ['Fuerteventura', 'Fuerteventura', 'spanija', 28.3587, -14.0537, [10 => [25, 22.5], 11 => [23, 21.5], 12 => [21, 20.5]]],

            // Turkey
            'antalija' => ['Antalya', 'Antalija', 'turska', 36.8969, 30.7133, [10 => [27, 25], 11 => [21, 22], 12 => [16, 19]]],
            'bodrum' => ['Bodrum', 'Bodrum', 'turska', 37.0344, 27.4305, [10 => [25, 23.5], 11 => [19, 20], 12 => [15, 18]]],
            'marmaris' => ['Marmaris', 'Marmaris', 'turska', 36.8550, 28.2742, [10 => [26, 24], 11 => [20, 21], 12 => [15, 18.5]]],
            'alanija' => ['Alanya', 'Alanija', 'turska', 36.5438, 32.0006, [10 => [27, 25], 11 => [21, 22], 12 => [17, 19.5]]],
            // Expansion round, 2026-08-11 — Kaş/Kalkan/Fethiye/Ölüdeniz on the Lycian coast
            // (between Bodrum/Marmaris and Antalya, blended placeholder); Side ~ Antalya
            // (right next door); Datça ~ Bodrum/Marmaris; Çeşme/Kuşadası near Izmir, north
            // of Bodrum and noticeably cooler.
            'kas' => ['Kaş', 'Kaš', 'turska', 36.2019, 29.6394, [10 => [26, 24.5], 11 => [20, 21.5], 12 => [16, 19]]],
            'kalkan' => ['Kalkan', 'Kalkan', 'turska', 36.2667, 29.4167, [10 => [26, 24.5], 11 => [20, 21.5], 12 => [16, 19]]],
            'fethije' => ['Fethiye', 'Fethije', 'turska', 36.6217, 29.1164, [10 => [25.5, 24], 11 => [19.5, 21], 12 => [15.5, 18.5]]],
            'oludeniz' => ['Ölüdeniz', 'Öludeniz', 'turska', 36.5497, 29.1156, [10 => [25.5, 24], 11 => [19.5, 21], 12 => [15.5, 18.5]]],
            'sajd' => ['Side', 'Sajd', 'turska', 36.7673, 31.3891, [10 => [27, 25], 11 => [21, 22], 12 => [16, 19]]],
            'datca' => ['Datça', 'Datča', 'turska', 36.7306, 27.6858, [10 => [25.5, 23.5], 11 => [19.5, 20.5], 12 => [15, 18]]],
            'cesme' => ['Çeşme', 'Češme', 'turska', 38.3236, 26.3061, [10 => [23.5, 22], 11 => [17.5, 19], 12 => [13.5, 17]]],
            'kusadasi' => ['Kuşadası', 'Kušadasi', 'turska', 37.8583, 27.2597, [10 => [24, 22.5], 11 => [18, 19.5], 12 => [14, 17.5]]],

            // Greek islands — attach to the existing 'grcka' country node, distinct from Atina
            // (which stays a city-break destination, not a swim one).
            'krit' => ['Heraklion (Crete)', 'Iraklion (Krit)', 'grcka', 35.3387, 25.1442, [10 => [24, 23.5], 11 => [20, 21.5], 12 => [16, 19]]],
            'rodos' => ['Rhodes', 'Rodos', 'grcka', 36.4341, 28.2176, [10 => [25, 24], 11 => [20, 22], 12 => [16, 19.5]]],
            'krf' => ['Corfu', 'Krf', 'grcka', 39.6243, 19.9217, [10 => [22, 23], 11 => [17, 20.5], 12 => [13, 18]]],
            'santorini' => ['Santorini', 'Santorini', 'grcka', 36.3932, 25.4615, [10 => [23, 23], 11 => [19, 21], 12 => [15, 19]]],
            'mikonos' => ['Mykonos', 'Mikonos', 'grcka', 37.4467, 25.3289, [10 => [23, 23], 11 => [18, 21], 12 => [14, 19]]],
            'kos' => ['Kos', 'Kos', 'grcka', 36.8933, 27.2877, [10 => [24, 23.5], 11 => [19, 21.5], 12 => [15, 19.5]]],

            // Expansion round, 2026-08-11 (owner's ask) — climate values below are rough
            // same-latitude-neighbor placeholders (e.g. Naxos/Paros/Milos copy Santorini's,
            // Zakynthos/Kefalonia/Lefkada copy Corfu's), NOT real data — `climate:import` runs
            // right after seeding and overwrites every row here with real Open-Meteo history.
            // Cyclades (alongside existing Santorini/Mykonos).
            'naksos' => ['Naxos', 'Naksos', 'grcka', 37.1036, 25.3766, [10 => [23, 23], 11 => [19, 21], 12 => [15, 19]]],
            'paros' => ['Paros', 'Paros', 'grcka', 37.0857, 25.1488, [10 => [23, 23], 11 => [19, 21], 12 => [15, 19]]],
            'milos' => ['Milos', 'Milos', 'grcka', 36.7231, 24.4230, [10 => [23, 23], 11 => [19, 21], 12 => [15, 19]]],
            // Dodecanese (alongside existing Rhodes/Kos).
            'karpatos' => ['Karpathos', 'Karpatos', 'grcka', 35.5069, 27.2144, [10 => [25, 24], 11 => [20, 22], 12 => [16, 19.5]]],
            'simi' => ['Symi', 'Simi', 'grcka', 36.6167, 27.8333, [10 => [25, 24], 11 => [20, 22], 12 => [16, 19.5]]],
            'kalimnos' => ['Kalymnos', 'Kalimnos', 'grcka', 36.9481, 26.9836, [10 => [24, 23.5], 11 => [19, 21.5], 12 => [15, 19.5]]],
            // Crete (alongside existing Heraklion) — different coast/microclimate.
            'hanja' => ['Chania', 'Hanja', 'grcka', 35.5138, 24.0180, [10 => [24, 23.5], 11 => [20, 21.5], 12 => [16, 19]]],
            'retimno' => ['Rethymno', 'Retimno', 'grcka', 35.3667, 24.4833, [10 => [24, 23.5], 11 => [20, 21.5], 12 => [16, 19]]],
            // Peloponnese mainland.
            'kalamata' => ['Kalamata', 'Kalamata', 'grcka', 37.0389, 22.1142, [10 => [23, 22.5], 11 => [18, 20.5], 12 => [14, 18]]],
            // Ionian (alongside existing Corfu) — owner's call, 2026-08-11: don't pre-exclude by
            // "north of Athens" instinct, let the real climate import decide what's actually warm.
            'zakintos' => ['Zakynthos', 'Zakintos', 'grcka', 37.7870, 20.8995, [10 => [22, 23], 11 => [17, 20.5], 12 => [13, 18]]],
            'kefalonija' => ['Kefalonia', 'Kefalonija', 'grcka', 38.1751, 20.4892, [10 => [21.5, 22.5], 11 => [16.5, 20], 12 => [12.5, 17.5]]],
            'lefkada' => ['Lefkada', 'Lefkada', 'grcka', 38.8333, 20.7167, [10 => [21, 22], 11 => [16, 19.5], 12 => [12, 17]]],
            // Sporades — owner's ask, explicitly required ("pod obavezno").
            'skopelos' => ['Skopelos', 'Skopelos', 'grcka', 39.1225, 23.7275, [10 => [20.5, 21.5], 11 => [15.5, 19], 12 => [11.5, 16]]],
            'skijatos' => ['Skiathos', 'Skijatos', 'grcka', 39.1633, 23.4919, [10 => [20.5, 21.5], 11 => [15.5, 19], 12 => [11.5, 16]]],

            // Italy — coastal south, distinct from Rim's city-break narrative.
            'taormina' => ['Taormina', 'Taormina', 'italija', 37.8525, 15.2870, [10 => [22, 23], 11 => [17, 21], 12 => [14, 18.5]]],
            'kaljari' => ['Cagliari', 'Kaljari', 'italija', 39.2238, 9.1217, [10 => [21, 22], 11 => [16, 19.5], 12 => [12, 17]]],
            // Pelagie Islands — owner's call, 2026-08-04: "Linosa i Lampedusa su late summer".
            // Italy's southernmost point, geologically part of the African shelf (closer to
            // Tunisia than to Sicily), similar latitude to Malta — genuinely one of the best
            // late-season swim picks in this whole list, not just added for completeness.
            'lampedusa' => ['Lampedusa', 'Lampedusa', 'italija', 35.5097, 12.6111, [10 => [24, 24.5], 11 => [20, 21.5], 12 => [17, 19]]],
            'linosa' => ['Linosa', 'Linoza', 'italija', 35.8667, 12.8667, [10 => [23.5, 24.5], 11 => [19.5, 21.5], 12 => [16.5, 19]]],

            // Portugal — Algarve, cools faster than the Mediterranean proper (Atlantic).
            'faro' => ['Faro', 'Faro', 'portugalija', 37.0194, -7.9304, [10 => [22, 20], 11 => [18, 18], 12 => [15, 16.5]]],
            'albufeira' => ['Albufeira', 'Albufeira', 'portugalija', 37.0891, -8.2504, [10 => [22, 20], 11 => [18, 18], 12 => [15, 16.5]]],
            'lagos' => ['Lagos', 'Lagos', 'portugalija', 37.1021, -8.6742, [10 => [21, 19.5], 11 => [17, 17.5], 12 => [14, 16]]],

            // Croatia's cities (split/dubrovnik/hvar) removed from this loop 2026-08-19 alongside
            // the country itself, same reasoning — see the $countries array comment above. Their
            // existing rows (climate, vibe_profile, tags) are untouched, just no longer visited
            // by re-seeding; the country no longer has a country_node to parent them under here.

            // Cape Verde — the only two islands with confirmed real direct DACH charter/
            // scheduled routes (TUIfly/Condor/Lufthansa -> Sal; Boa Vista's own international
            // airport serves the same charter market via Iberostar etc.), 2026-08-19. Placeholder
            // climate below (Atlantic subtropical, stays warm nearly flat across the season, real
            // draw is exactly THAT stability) — overwritten by climate:import right after seeding.
            'santa_marija' => ['Santa Maria', 'Santa Marija', 'zelenortska_ostrva', 16.599, -22.904, [10 => [29, 26.5], 11 => [28, 26], 12 => [27, 25.5]]],
            'sal_rej' => ['Sal Rei', 'Sal Rej', 'zelenortska_ostrva', 16.177, -22.918, [10 => [29, 26.5], 11 => [28, 26], 12 => [27, 25.5]]],
        ];

        foreach ($cities as $slug => [$en, $sr, $countrySlug, $lat, $lng, $climate]) {
            $city = TaxonomyNode::updateOrCreate(
                ['type' => 'city', 'slug' => $slug],
                [
                    'label' => $en,
                    'parent_id' => $countryNodes[$countrySlug]->id, 'sort_order' => 0,
                    'meta' => ['lat' => $lat, 'lng' => $lng, 'on_sea' => true, 'has_beach' => true],
                ],
            );
            $city->translations()->updateOrCreate(
                ['translatable_type' => TaxonomyNode::class, 'translatable_id' => $city->id, 'field' => 'label', 'locale' => 'sr'],
                ['value' => $sr, 'source_hash' => hash('crc32', $en), 'status' => 'human'],
            );

            $climateRows = [];
            foreach ($climate as $month => [$air, $sea]) {
                $climateRows[] = [
                    'taxonomy_node_id' => $city->id, 'month' => $month,
                    'avg_temp_c' => $air, 'sea_temp_c' => $sea,
                    'source' => 'manual_estimate', 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            TaxonomyNodeClimate::where('taxonomy_node_id', $city->id)->delete();
            TaxonomyNodeClimate::insert($climateRows);

            $location = Location::updateOrCreate(
                ['booking_dest_id' => "test_{$slug}_city"],
                [
                    'dest_type' => 'city', 'name' => $en,
                    'country_code' => $countries[$countrySlug]['iso'] ?? strtoupper(substr($countrySlug, 0, 2)),
                    'source' => 'manual_test',
                ],
            );
            $city->update(['booking_location_id' => $location->id]);
        }
    }

    /**
     * Hospitality/local_stores cost meta + cultural_availability tiers for the 10 swim-theme
     * countries (2026-07-30, see wizard_architecture "BudgetEstimationEngine" +
     * "cultural_availability engine"). No usable free API exists for either — WhereNext (the
     * source already used for climate-adjacent research) turned out to be expat-relocation
     * data (monthly cost of living, `diningOut` literally $0), not per-meal tourist pricing,
     * and covers none of these 32 cities anyway (checked directly, 2026-07-30). Same
     * `manual_estimate` convention as the rest of this seeder — real numbers, reasoned
     * estimates, not verified against a live source. Seeded at COUNTRY level; a specific
     * resort town can get its own override row later via TaxonomyNode::climateFor()-style
     * parent-fallback (culturalTierFor() uses the identical pattern).
     *
     * `tier` convention (see cultural_availability migration): 1 = most freely available,
     * 4 = most restricted. Consistent across every category on purpose.
     */
    private function seedSwimCountryProfiles(): void
    {
        // meal/coffee/beer in EUR, tourist-resort pricing (not big-city/local pricing)
        $hospitality = [
            'egipat' => ['meal' => 12, 'coffee' => 2.5, 'beer' => 3.0],
            'kipar' => ['meal' => 18, 'coffee' => 3.5, 'beer' => 4.5],
            'malta' => ['meal' => 20, 'coffee' => 3.0, 'beer' => 4.0],
            'tunis' => ['meal' => 10, 'coffee' => 2.0, 'beer' => 3.5],
            'spanija' => ['meal' => 16, 'coffee' => 2.5, 'beer' => 3.0],
            'turska' => ['meal' => 12, 'coffee' => 2.5, 'beer' => 4.0],
            'portugalija' => ['meal' => 14, 'coffee' => 1.2, 'beer' => 2.5],
            'hrvatska' => ['meal' => 18, 'coffee' => 2.0, 'beer' => 3.5],
            'grcka' => ['meal' => 16, 'coffee' => 2.5, 'beer' => 3.5],
            'italija' => ['meal' => 20, 'coffee' => 1.3, 'beer' => 4.5],
            // Added 2026-08-19 — researched (WebSearch, not pure guesswork): tourist-front beer
            // ~EUR4-5/pint, tascas EUR4-8/meal, tourist-zone seafood EUR11-23, so this sits
            // squarely in the Egypt/Tunisia tier despite island-import costs pushing it up
            // slightly from mainland-Africa prices.
            'zelenortska_ostrva' => ['meal' => 14, 'coffee' => 2.5, 'beer' => 4.5],
        ];

        // store beer / meat per kg / cigarettes pack, in EUR
        $localStores = [
            'egipat' => ['beer' => 1.5, 'meat' => 8, 'cigarettes' => 3.0],
            'kipar' => ['beer' => 1.8, 'meat' => 12, 'cigarettes' => 5.5],
            'malta' => ['beer' => 1.5, 'meat' => 11, 'cigarettes' => 5.5],
            'tunis' => ['beer' => 2.0, 'meat' => 9, 'cigarettes' => 4.0],
            'spanija' => ['beer' => 1.0, 'meat' => 10, 'cigarettes' => 5.0],
            'turska' => ['beer' => 2.5, 'meat' => 9, 'cigarettes' => 3.0],
            'portugalija' => ['beer' => 0.8, 'meat' => 9, 'cigarettes' => 5.5],
            'hrvatska' => ['beer' => 1.2, 'meat' => 10, 'cigarettes' => 4.0],
            'grcka' => ['beer' => 1.3, 'meat' => 10, 'cigarettes' => 4.5],
            'italija' => ['beer' => 1.3, 'meat' => 13, 'cigarettes' => 6.0],
            // Store beer researched at ~150-200 CVE/25cl bottle (~EUR1.5-1.9); meat/cigarettes
            // no direct source found, estimated at the same Egypt/Tunisia tier.
            'zelenortska_ostrva' => ['beer' => 1.8, 'meat' => 9, 'cigarettes' => 4.5],
        ];

        // tier: 1=most free/available, 4=most restricted — see class docblock above
        $cultural = [
            'egipat' => ['alcohol' => 2, 'pork' => 3, 'halal' => 1, 'vegan' => 3, 'organic' => 4, 'cannabis' => 3, 'dress_code' => 3, 'lgbtq_friendly' => 4, 'tap_water' => 4],
            'kipar' => ['alcohol' => 1, 'pork' => 1, 'halal' => 3, 'vegan' => 2, 'organic' => 2, 'cannabis' => 3, 'dress_code' => 1, 'lgbtq_friendly' => 2, 'tap_water' => 1],
            'malta' => ['alcohol' => 1, 'pork' => 1, 'halal' => 3, 'vegan' => 2, 'organic' => 2, 'cannabis' => 2, 'dress_code' => 1, 'lgbtq_friendly' => 1, 'tap_water' => 1],
            'tunis' => ['alcohol' => 3, 'pork' => 4, 'halal' => 1, 'vegan' => 3, 'organic' => 4, 'cannabis' => 3, 'dress_code' => 3, 'lgbtq_friendly' => 4, 'tap_water' => 3],
            'spanija' => ['alcohol' => 1, 'pork' => 1, 'halal' => 2, 'vegan' => 1, 'organic' => 1, 'cannabis' => 2, 'dress_code' => 1, 'lgbtq_friendly' => 1, 'tap_water' => 1],
            'turska' => ['alcohol' => 1, 'pork' => 3, 'halal' => 1, 'vegan' => 2, 'organic' => 3, 'cannabis' => 4, 'dress_code' => 2, 'lgbtq_friendly' => 3, 'tap_water' => 3],
            'portugalija' => ['alcohol' => 1, 'pork' => 1, 'halal' => 3, 'vegan' => 2, 'organic' => 2, 'cannabis' => 2, 'dress_code' => 1, 'lgbtq_friendly' => 1, 'tap_water' => 1],
            'hrvatska' => ['alcohol' => 1, 'pork' => 1, 'halal' => 3, 'vegan' => 2, 'organic' => 2, 'cannabis' => 3, 'dress_code' => 1, 'lgbtq_friendly' => 2, 'tap_water' => 1],
            'grcka' => ['alcohol' => 1, 'pork' => 1, 'halal' => 3, 'vegan' => 2, 'organic' => 2, 'cannabis' => 3, 'dress_code' => 1, 'lgbtq_friendly' => 1, 'tap_water' => 1],
            'italija' => ['alcohol' => 1, 'pork' => 1, 'halal' => 3, 'vegan' => 2, 'organic' => 1, 'cannabis' => 3, 'dress_code' => 1, 'lgbtq_friendly' => 2, 'tap_water' => 1],
            // Researched (WebSearch), 2026-08-19: alcohol/pork freely available (Catholic-
            // majority, real local beer/grogue culture) — halal tier 3, not 1: no dedicated halal
            // butcher operates on Sal or Boa Vista specifically (the two islands actually seeded
            // here), only in Praia. lgbtq_friendly tier 2: same-sex activity legal, employment
            // discrimination banned since 2008, repeatedly surveyed as Africa's most tolerant —
            // genuinely better than most of this list, capped at 2 (not 1) since it isn't an
            // established LGBT-destination brand the way Spain/Malta/Portugal are. tap_water
            // tier 4: desalinated seawater, real stomach-upset risk, same severity as Egypt.
            // organic tier 4: small remote island economy, imports most food.
            'zelenortska_ostrva' => ['alcohol' => 1, 'pork' => 1, 'halal' => 3, 'vegan' => 2, 'organic' => 4, 'cannabis' => 3, 'dress_code' => 1, 'lgbtq_friendly' => 2, 'tap_water' => 4],
        ];

        $labels = [
            1 => 'Slobodno/svuda dostupno', 2 => 'Uglavnom dostupno', 3 => 'Ograničeno/na upit', 4 => 'Praktično nedostupno',
        ];

        // For the "svejedno koji grad" case — see SearchSessionQueryCompiler::destinationNode(),
        // 2026-07-30 bug: picking a country but no specific city silently had no `location` at
        // all, because only CITIES got a mock Location row in seedSwimDestinations(). Same
        // test_*/manual_test convention, one level up.
        $isoCodes = [
            'egipat' => 'EG', 'kipar' => 'CY', 'malta' => 'MT', 'tunis' => 'TN', 'spanija' => 'ES',
            'turska' => 'TR', 'portugalija' => 'PT', 'hrvatska' => 'HR', 'grcka' => 'GR', 'italija' => 'IT',
            'zelenortska_ostrva' => 'CV',
        ];

        foreach ($hospitality as $slug => $h) {
            $country = TaxonomyNode::where('type', 'country')->where('slug', $slug)->first();
            if (! $country) {
                continue;
            }

            $ls = $localStores[$slug];
            $meta = $country->meta ?? [];
            // Owner's ask, 2026-08-13: city badge on the City step ("Heraklion (Crete) GREECE")
            // overlapped the city name — a 2-letter code fits without collision. Reuses the same
            // $isoCodes map already used for Location.country_code just below, now also written
            // onto the node itself so the frontend can read it directly off `parent.meta`.
            $meta['iso_code'] = $isoCodes[$slug] ?? strtoupper(substr($slug, 0, 2));
            $meta['hospitality'] = [
                'avg_restaurant_meal_eur' => $h['meal'], 'avg_cafe_coffee_eur' => $h['coffee'], 'avg_bar_beer_eur' => $h['beer'],
                'priced_at' => '2026-07-30', 'source' => 'manual_estimate',
            ];
            $meta['local_stores'] = [
                'avg_store_beer_eur' => $ls['beer'], 'avg_meat_price_eur_kg' => $ls['meat'], 'avg_cigarettes_pack_eur' => $ls['cigarettes'],
                'priced_at' => '2026-07-30', 'source' => 'manual_estimate',
            ];
            $country->update(['meta' => $meta]);

            if (! $country->booking_location_id) {
                $location = Location::updateOrCreate(
                    ['booking_dest_id' => "test_{$slug}_country"],
                    [
                        'dest_type' => 'country', 'name' => $country->label,
                        'country_code' => $isoCodes[$slug] ?? strtoupper(substr($slug, 0, 2)),
                        'source' => 'manual_test',
                    ],
                );
                $country->update(['booking_location_id' => $location->id]);
            }

            $rows = [];
            foreach ($cultural[$slug] as $category => $tier) {
                $rows[] = [
                    'taxonomy_node_id' => $country->id, 'category' => $category, 'tier' => $tier,
                    'label' => $labels[$tier], 'source' => 'manual_estimate',
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            CulturalAvailability::where('taxonomy_node_id', $country->id)->delete();
            CulturalAvailability::insert($rows);
        }
    }

    /**
     * Vibe/atmosphere profile per city (all 32) and country (all 10) — owner's call,
     * 2026-08-04, while paused on other work: "napisi one opise... koliko nadjes neki presek,
     * za sad ne mora ni tolko tacno ni poeticno, bitno da razdvojimo da ima i dobro i lose."
     * Deliberately NOT polished marketing copy (see the persona-specific "seductive description"
     * AI pipeline discussed separately, still owner's manual/AI job) — this is a plain,
     * reasoned-from-general-knowledge FACT layer: which persona a place actually suits, and
     * which it doesn't. Owner's own example, verbatim: "Paceville / St Julians nije za
     * porodice" — `avoid_for` including 'porodica' (a group_type slug, not persona) captures
     * exactly that. `source: manual_estimate` throughout — general knowledge, not a live source,
     * same honesty convention as every other piece of content in this seeder. Doubles as a
     * sanity check: if a family ever gets Paceville suggested, that's a bug, not a preference.
     */
    private function seedCityAndCountryVibeProfiles(): void
    {
        // slug => [description, good_for persona slugs, avoid_for slugs (persona OR group_type)]
        $cities = [
            'hurgada' => ['Big all-inclusive resort belt on the Red Sea — diving reefs right offshore, built for families and groups who want warm water without much else to plan.', ['istrazivac'], []],
            'sarm_el_seik' => ['Upscale diving hub with a livelier Naama Bay strip after dark, but still resort-family-friendly overall.', ['istrazivac'], []],
            'marsa_alam' => ['Remote and quiet, built around diving and reef life — barely any nightlife, best for people who came for the water, not the bars.', ['istrazivac', 'flegma'], ['partijaner']],
            // Expansion round, 2026-08-11 additions — same manual_estimate/general-knowledge
            // convention, written 2026-08-14 alongside the price estimates (see WizardSeeder's
            // owner-referenced neighbor mapping in seedSwimDestinations for which city each was
            // compared against).
            'el_guna' => ['Purpose-built lagoon resort town just north of Hurghada — more polished and planned than Hurghada itself, popular with windsurfers/kitesurfers, marina bars but no real club scene.', ['istrazivac'], []],
            'dahab' => ["Laid-back diver/backpacker town, a totally different character from Sharm — Bedouin-camp beach cafes, the Blue Hole, barely any nightlife.", ['istrazivac', 'flegma'], ['partijaner']],
            'nuvejba' => ['Even quieter and more remote than Dahab — undeveloped beach camps, mostly divers and people actively seeking solitude.', ['flegma', 'istrazivac'], ['partijaner']],
            'taba' => ['Small border-town resort cluster near the Israel/Jordan crossing — quiet, low-key, far less developed than Sharm or Hurghada.', ['flegma'], ['partijaner']],
            'safaga' => ['Windsurfing/kitesurfing hub with a working port-town feel — quieter and less resort-dense than Hurghada.', ['istrazivac'], []],
            'soma_bej' => ['Isolated purpose-built peninsula resort (golf course, spa hotels) — upscale and self-contained, essentially no town or nightlife outside the resorts.', ['flegma'], ['partijaner']],

            'larnaka' => ['Relaxed seafront town, historic, easygoing — not a party destination.', ['flegma'], []],
            'pafos' => ['UNESCO mosaics and archaeological parks — culture-and-beach, quiet evenings, popular with families and couples.', ['istrazivac'], ['partijaner']],
            'ajia_napa' => ["Cyprus's actual party capital — beach clubs, foam parties, bars open till sunrise. Great for a group that wants to go out, a rough fit for a family trip.", ['partijaner'], ['porodica']],

            'melieha' => ["Quiet family beach town in Malta's north, the calmest of the island's three real beach spots.", ['flegma'], ['partijaner']],
            'sliema' => ['Walkable seafront promenade, shopping, ferries to Valletta — comfortable middle ground, not wild, not sleepy.', [], []],
            'st_julians' => ["Paceville is here — Malta's entire nightlife scene packed into a few blocks of clubs and bars. Great for a group that wants to go out every night, a genuinely bad fit for a family trip.", ['partijaner'], ['porodica']],

            'hamamet' => ['Big resort belt, golf courses, spas, family package-holiday territory — moderate nightlife, nothing wild.', [], []],
            'djerba' => ['Island with real Tunisian character beyond the resorts, relaxed pace, good for culture-curious travelers, light on nightlife.', ['istrazivac', 'flegma'], ['partijaner']],
            'susa' => ['Livelier resort town with an old medina and some backpacker energy — busier than Hammamet, still not a hard party scene.', ['istrazivac'], []],
            'monastir' => ['Quiet marina town, mostly resort hotels, low-key evenings — built for families, not nightlife.', [], ['partijaner']],
            // Expansion round, 2026-08-11 additions — see the el_guna comment above.
            'nabel' => ["Right next to Hammamet, known for its pottery/ceramics and a big weekly market — quieter and more local-feeling than Hammamet's resort strip.", [], []],
            'mahdija' => ['Historic fishing town with a real medina — quieter and more authentic than the big resort belt further north, relaxed pace.', ['istrazivac', 'flegma'], ['partijaner']],
            'sfaks' => ["Tunisia's second-largest city — a real working port city more than a resort town, genuine local life rather than a classic beach-holiday strip.", ['istrazivac'], ['partijaner']],
            'zarzis' => ['Quiet resort town near Djerba, olive groves — calmer and less touristy than Djerba proper.', ['flegma'], ['partijaner']],
            'tabarka' => ['Northern coast, known for coral diving and pine forests — noticeably cooler and greener than the southern resort belt, quiet evenings.', ['istrazivac', 'flegma'], ['partijaner']],
            'bizerta' => ["Tunisia's northernmost city, a picturesque old harbor — more authentic port-city mix than resort strip.", ['istrazivac'], ['partijaner']],

            'tenerife' => ['Big enough to be two different trips in one — Playa de las Américas is loud and club-heavy, Puerto de la Cruz up north is calm and old-town. Worth being specific about which end you mean.', [], []],
            'gran_kanarija' => ["Same split personality as Tenerife — Playa del Inglés/Maspalomas has a famous party scene, the rest of the island is quiet dunes and fishing villages.", [], []],
            'lansarote' => ['Volcanic landscapes, surf spots, calmer overall than its Canary neighbors — light on nightlife, strong on scenery.', ['istrazivac', 'flegma'], ['partijaner']],
            'fuerteventura' => ['Windsurfing and kite-surfing capital of the Canaries — sporty, laid-back, not a club destination.', ['istrazivac', 'flegma'], ['partijaner']],

            'antalija' => ["Sprawling — Lara/Belek's all-inclusive resort belt is deeply family-oriented, while Kaleiçi's old town has real nightlife after dark.", [], []],
            'bodrum' => ["Turkey's answer to St. Tropez — yacht culture, upscale beach clubs, a real party scene once the sun goes down.", ['partijaner', 'istrazivac'], ['porodica']],
            'marmaris' => ['Package-holiday party town, loud late into the night — fun for a group trip, not built for families.', ['partijaner'], ['porodica']],
            'alanija' => ["Huge, mostly family package-tourism resort belt — has a nightlife strip but it's not the main character.", [], []],
            // Expansion round, 2026-08-11 additions — see the el_guna comment above.
            'kas' => ['Boutique diving/paragliding town on the Lycian coast — upscale-bohemian, no big resorts, wine bars rather than clubs.', ['istrazivac', 'flegma'], ['partijaner']],
            'kalkan' => ['Small upscale harbor town near Kaş, boutique villas — quiet, sophisticated, expensive for its size, not a nightlife destination.', ['flegma', 'istrazivac'], ['partijaner']],
            'fethije' => ['Larger town and gateway to the Blue Lagoon, big paragliding hub — a lively marina with a mix of backpacker and family tourism, moderate nightlife.', ['istrazivac'], []],
            'oludeniz' => ["Home to the iconic Blue Lagoon beach right next to Fethiye — resort-heavy, family and photo-tourist destination, limited nightlife.", ['istrazivac'], []],
            'sajd' => ['Ancient ruins sit right in the resort town (a Temple of Apollo on the beach) — a family-oriented all-inclusive resort belt similar to Antalya\'s Lara/Belek.', ['istrazivac'], []],
            'datca' => ["Quieter peninsula beyond Bodrum's crowds, known for almond blossoms — favored by domestic Turkish tourists seeking a calmer alternative to Bodrum.", ['flegma'], ['partijaner']],
            'cesme' => ['Popular with the Izmir/Istanbul domestic crowd, windsurfing beaches — some upscale beach clubs, but far less internationally touristed than Bodrum.', [], []],
            'kusadasi' => ['Major cruise-ship port and gateway to Ephesus — a busy touristy resort strip, moderate nightlife catering to cruise/package tourists.', ['istrazivac'], []],

            'krit' => ['Crete is enormous — Heraklion itself is a real city, but Malia/Hersonissos nearby are infamous for hard partying. Depends entirely which part you land in.', [], []],
            'rodos' => ['Same split as Crete — Rhodes Old Town is medieval-castle romantic, Faliraki a few km away is a legendary party strip.', [], []],
            'krf' => ["Most of Corfu is quiet olive groves and pastel villages — except Kavos, one of Greece's most famous party resorts, tucked in the south.", [], []],
            'santorini' => ['Postcard sunsets, cliffside walkways, honeymoon central — beautiful but genuinely impractical for young kids (stairs everywhere) and expensive for a tight budget.', ['istrazivac'], ['porodica']],
            'mikonos' => ["Greece's most famous party island — beach clubs, international DJs, a serious price tag to match.", ['partijaner'], ['porodica']],
            'kos' => ['Calmer and flatter than its Cycladic cousins, more family package-resort energy — good for biking, moderate nightlife in Kos Town.', [], []],

            'taormina' => ['Clifftop, ancient Greek theatre, expensive and romantic — a couples and culture-lovers town, not a party or budget destination.', ['istrazivac'], ['partijaner']],
            'kaljari' => ["Sardinia's real capital city — beaches right by an actual working city, good food scene, moderate nightlife without being a strip.", ['gurman', 'istrazivac'], []],
            'lampedusa' => ["Italy's southernmost point, closer to Africa than to Sicily — famous for the turquoise Rabbit Beach and turtle nesting, built around diving and nature, not nightlife.", ['istrazivac', 'flegma'], ['partijaner']],
            'linosa' => ['A tiny volcanic island near Lampedusa, even quieter and more remote — black-sand beaches, a handful of restaurants, no real nightlife at all.', ['flegma', 'istrazivac'], ['partijaner']],

            'faro' => ["Algarve's quiet gateway city — historic old town, fewer crowds than the resort strips further west.", ['istrazivac'], []],
            'albufeira' => ["The Algarve's party capital — 'The Strip' is wall-to-wall bars and clubs, though the old town and family beaches sit right alongside it.", ['partijaner'], ['porodica']],
            'lagos' => ['Surf culture, sea caves, a livelier young-crowd energy than most of the Algarve — moderate nightlife, big on outdoor activities.', ['istrazivac', 'partijaner'], []],

            'split' => ['Living Roman palace as a city center — real bar scene at night, walkable, good food, popular with a younger crowd.', ['gurman', 'istrazivac'], []],
            'dubrovnik' => ['Iconic walled city, expensive, romantic and culture-tourism heavy — not primarily a nightlife or budget destination.', ['istrazivac'], []],
            'hvar' => ["Croatia's actual jet-set party island — yacht clubs, expensive bars, a real scene in summer. Not the place for a tight budget or a family trip.", ['partijaner', 'istrazivac'], ['porodica']],

            // Added 2026-08-19, researched (WebSearch) rather than pure general knowledge —
            // see seedSwimDestinations' Cape Verde comment for why these two specifically.
            'santa_marija' => ["Sal's real town — restaurants, bars and dive shops line the main strip, genuinely one of the world's top kitesurfing/windsurfing spots, plus regular whale shark and manta ray diving trips. More going on than Boa Vista, but still resort-relaxed, not a club scene.", ['istrazivac'], []],
            'sal_rej' => ["Boa Vista's main town — vast, near-empty desert-dune beaches, a famous shipwreck photo spot, and one of the world's most important loggerhead turtle nesting sites in summer. Wilder and quieter than Santa Maria, built for peace and long beach walks over nightlife.", ['istrazivac', 'flegma'], ['partijaner']],
        ];

        foreach ($cities as $slug => [$description, $goodFor, $avoidFor]) {
            $city = TaxonomyNode::where('type', 'city')->where('slug', $slug)->first();
            if (! $city) {
                continue;
            }
            $city->update(['meta' => [...($city->meta ?? []), 'vibe_profile' => [
                'description' => $description, 'good_for' => $goodFor, 'avoid_for' => $avoidFor,
                'source' => 'manual_estimate',
            ]]]);
        }

        // Country-level: more averaged (a country this small usually mixes multiple vibes), so
        // description-only rather than forcing good_for/avoid_for tags that would just be a
        // watered-down version of what the cities above already say precisely.
        $countryDescriptions = [
            'egipat' => 'Red Sea resort culture built around diving and warm winters — mostly relaxed, family/group friendly, nightlife exists but is not the main draw anywhere.',
            'kipar' => 'A real split character — historic, relaxed towns (Larnaca, Paphos) alongside Ayia Napa, one of the Mediterranean\'s harder party destinations.',
            'malta' => "Compact island where Valletta/Sliema are calm and walkable, but St. Julian's/Paceville concentrates almost all of Malta's nightlife into one small area.",
            'tunis' => 'Resort-belt package-holiday country overall — relaxed, family-oriented, moderate nightlife, real local culture available just outside the hotel zones.',
            'spanija' => 'The Canary Islands specifically — a real mix, from the loud party strips of southern Tenerife/Gran Canaria to the quiet volcanic calm of Lanzarote/Fuerteventura.',
            'turska' => "Ranges from Alanya's family resort belt to Bodrum's yacht-party scene to Marmaris's loud package-tourism strip — depends heavily which coastal city.",
            'portugalija' => 'The Algarve — a mix of surf/young-crowd energy (Lagos), an actual party capital (Albufeira), and quieter historic towns (Faro).',
            'hrvatska' => 'Coolest of the ten by late season — real historic cities (Split, Dubrovnik) alongside Hvar\'s expensive jet-set party scene.',
            'grcka' => 'The islands vary enormously — Santorini/Mykonos are upscale and pricey, Corfu/Rhodes/Crete each hide a famous party strip next to much calmer areas.',
            'italija' => 'Southern coastal Italy here — Taormina is romantic and expensive, Cagliari is a real working city with beaches attached.',
            'zelenortska_ostrva' => 'Atlantic islands off West Africa, genuinely warm nearly year-round rather than seasonally — Sal (Santa Maria) is the livelier of the two real package-holiday islands, Boa Vista (Sal Rei) is wilder and quieter with vast empty beaches and a real turtle-nesting nature draw.',
        ];

        foreach ($countryDescriptions as $slug => $description) {
            $country = TaxonomyNode::where('type', 'country')->where('slug', $slug)->first();
            if (! $country) {
                continue;
            }
            $country->update(['meta' => [...($country->meta ?? []), 'vibe_profile' => [
                'description' => $description, 'source' => 'manual_estimate',
            ]]]);
        }
    }

    /**
     * Populates the meta['drinks']/meta['atmosphere']/meta['food'] keys GeographyResolver's
     * match_score actually reads (unlike vibe_profile above, which is hover-card text only).
     * Owner's split, 2026-08-11: "razdvojimo Pub i Rave, uz pub da ide Pivo, uz Rave... samo
     * rave lokacije, ostalo ne mozemo da koristimo" — Rave is intentionally a short, strict
     * list (only destinations whose PRIMARY character is nightlife, not a place that merely
     * contains a known party sub-district — Corfu/Rhodes/Crete/Tenerife/Gran Canaria are all
     * genuinely mixed per their own vibe_profile text above and are deliberately excluded here).
     */
    private function seedSwimAtmosphereTags(): void
    {
        $cityAtmosphere = [
            'ajia_napa' => ['atmosphere' => ['zivahna_nocna_zabava']],
            'st_julians' => ['atmosphere' => ['zivahna_nocna_zabava'], 'drinks' => ['pivo']],
            // Sliema, 2026-08-11 (owner's ground-truth) — no Paceville-level rave scene, but has
            // a real, smaller pub presence and sits ~10min walk from St. Julian's/Paceville. Not
            // full rave, but a legitimate second-tier option for a nightlife-seeking traveler —
            // modeled as real data (pivo tag) rather than a geo-distance/proximity algorithm.
            'sliema' => ['drinks' => ['pivo']],
            'bodrum' => ['atmosphere' => ['zivahna_nocna_zabava']],
            'marmaris' => ['atmosphere' => ['zivahna_nocna_zabava']],
            'mikonos' => ['atmosphere' => ['zivahna_nocna_zabava']],
            'albufeira' => ['atmosphere' => ['zivahna_nocna_zabava']],
            'hvar' => ['atmosphere' => ['zivahna_nocna_zabava']],
            'kaljari' => ['food' => ['dobra_hrana']],
            'split' => ['food' => ['dobra_hrana']],
            // City-level food/wine additions, 2026-08-12 — owner's ask, same tier>=2 standard as
            // exploration/beach (seedExplorationAndBeachTags): a country-wide food reputation
            // doesn't mean every city in it earns the tag, and conversely a city can have a real
            // culinary reputation even where the country overall doesn't (e.g. Tenerife's actual
            // volcanic wine appellation vs. Spain's food reputation being more Basque/Catalan
            // than Canarian).
            'krit' => ['food' => ['dobra_hrana']], // Cretan cuisine — distinct within Greek food
            'kalamata' => ['food' => ['dobra_hrana']], // world-famous olives/olive oil
            'taormina' => ['food' => ['dobra_hrana']], // Sicilian cuisine
            'tenerife' => ['drinks' => ['vino']], // real DO volcanic wine region
        ];

        foreach ($cityAtmosphere as $slug => $tags) {
            $city = TaxonomyNode::where('type', 'city')->where('slug', $slug)->first();
            if (! $city) {
                continue;
            }
            $meta = $city->meta ?? [];
            foreach ($tags as $key => $values) {
                $meta[$key] = array_values(array_unique([...($meta[$key] ?? []), ...$values]));
            }
            $city->update(['meta' => $meta]);
        }

        // Country-level: British-colonial pub/beer heritage (Malta, Cyprus) and the three
        // standout Mediterranean food/wine reputations — applied here so the "pick a region"
        // wizard step (country-type suggestions) also differentiates, not just city picking.
        $countryAtmosphere = [
            'malta' => ['drinks' => ['pivo']],
            'kipar' => ['drinks' => ['pivo', 'kafa']],
            'grcka' => ['food' => ['dobra_hrana'], 'drinks' => ['vino', 'kafa']],
            'italija' => ['food' => ['dobra_hrana'], 'drinks' => ['vino', 'kafa']],
            'turska' => ['food' => ['dobra_hrana'], 'drinks' => ['kafa', 'caj']],
            // Coffee/Tea Culture, 2026-08-21 — Turkish coffee (UNESCO-listed) and çay both
            // genuinely iconic there, hence both tags on turska above. Egypt/Tunisia get tea
            // only (shai and mint tea respectively are the defining daily-life drink, not
            // coffee specifically) — deliberately as short/strict a list as Rave above, not
            // "every country has cafes so everyone qualifies".
            'egipat' => ['drinks' => ['caj']],
            'tunis' => ['drinks' => ['caj']],
            // 2026-08-12 additions, same tier>=2 standard: Spain/Portugal's globally-recognized
            // food reputations, Croatia's real (if smaller-scale) Dalmatian wine culture.
            // Owner's catch, 2026-08-13: Spain's own real wine reputation (Rioja, Ribera del
            // Duero, Cava, Sherry) was missing here despite Tenerife already carrying `vino` at
            // city level — city tags don't propagate to country for pivo/vino/dobra_hrana (see
            // propagateCityAtmosphereToCountry's scoped exclusion list), so this needed its own
            // explicit entry, same as every other country here.
            'spanija' => ['food' => ['dobra_hrana'], 'drinks' => ['vino']],
            'portugalija' => ['food' => ['dobra_hrana']],
            'hrvatska' => ['drinks' => ['vino']],
        ];

        foreach ($countryAtmosphere as $slug => $tags) {
            $country = TaxonomyNode::where('type', 'country')->where('slug', $slug)->first();
            if (! $country) {
                continue;
            }
            $meta = $country->meta ?? [];
            foreach ($tags as $key => $values) {
                $meta[$key] = array_values(array_unique([...($meta[$key] ?? []), ...$values]));
            }
            $country->update(['meta' => $meta]);
        }
    }

    /**
     * Owner's ask, 2026-08-17: `pivo`/`vino` at country level (see $countryAtmosphere above)
     * describe plain AVAILABILITY ("you can buy this here"), not a curated reputation claim like
     * `dobra_hrana` — beer and wine are sold in any corner store across a whole country, so if
     * Malta as a whole has `pivo`, then Sliema/St. Julian's/Mellieħa all genuinely have it too,
     * not just whichever city happened to seed it directly. Deliberately does NOT include
     * `dobra_hrana` — that stays independently curated per level on purpose (a country's food
     * reputation doesn't mean literally every city in it earned it), same reasoning
     * propagateCityAtmosphereToCountry's docblock already gives for the upward direction.
     *
     * This existing straight-copy pattern (idempotent seeder pass, not a live query join or a
     * cron) matches propagateCityAtmosphereToCountry below — re-running `db:seed` is already the
     * established way to fix/refresh any of this taxonomy, no new mechanism needed.
     */
    private function propagateCountryDrinksToCities(): void
    {
        // kafa/caj added 2026-08-21 — same reasoning as pivo/vino: Coffee/Tea Culture, once
        // it's a genuine national trait, holds true in every resort town in that country too,
        // not just wherever happened to be curated first.
        $propagatedTags = ['pivo', 'vino', 'kafa', 'caj'];

        $countries = TaxonomyNode::where('type', 'country')->with('children')->get();

        foreach ($countries as $country) {
            $toAdd = collect($country->meta['drinks'] ?? [])->intersect($propagatedTags);
            if ($toAdd->isEmpty()) {
                continue;
            }

            foreach ($country->children as $city) {
                $meta = $city->meta ?? [];
                $meta['drinks'] = array_values(array_unique([...($meta['drinks'] ?? []), ...$toAdd->values()->all()]));
                $city->update(['meta' => $meta]);
            }
        }
    }

    /**
     * Two holistic 0-3 city ratings — owner's ask, 2026-08-12. Only tier >= 2 adds the matching
     * preference_tag (meta.atmosphere), same "tag it or don't, no fractional score" convention
     * as the Pub/Rave pass above — a 0/1 rating carries no real signal either way.
     *
     * `exploration`: "is it worth leaving the hotel to look around, regardless of why" — history
     * OR standout natural scenery both count equally (owner's own example: Lefkada has no real
     * history but is genuinely one of the most photographed coastlines in the world, and that's
     * worth exactly as much as Corfu's old town). 0 = pure beach/resort, nothing to see. 1 = a
     * real but modest point of interest (a small fort, an old-town core) — half a day, not the
     * main reason to go. 2 = a genuinely well-known site or standout natural feature, worth the
     * trip on its own. 3 = globally recognized, bucket-list-level (UNESCO-tier history or a
     * true natural wonder). Maps to `van_utabanih_staza` at tier >= 2.
     *
     * `beach`: purely the beach itself, not the town — "nekima nije samo pesak i uso u vodu."
     * 0 = functional, unremarkable. 1 = decent, pleasant, does the job. 2 = genuinely beautiful,
     * a beach people specifically seek out. 3 = world-famous, routinely on "world's best
     * beaches" lists (Skiathos's Koukounaries corrected in after the owner's live catch — pine
     * forest to the sand, always near the top of those lists; the general-knowledge first pass
     * had it wrong). Maps to `lepe_plaze` at tier >= 2.
     *
     * Both are the owner's own general-knowledge judgment calls, same "odokativno" spirit as
     * the cultural_availability tiers — not derived from any dataset.
     */
    private function seedExplorationAndBeachTags(): void
    {
        // slug => [exploration_tier, beach_tier]
        $ratings = [
            // Greece
            'rodos' => [3, 1], 'krit' => [3, 1], 'santorini' => [3, 1], 'krf' => [2, 1],
            'naksos' => [2, 1], 'milos' => [2, 2], 'simi' => [2, 1], 'hanja' => [2, 3],
            'retimno' => [2, 2], 'zakintos' => [2, 3], 'kefalonija' => [2, 3], 'lefkada' => [2, 3],
            'mikonos' => [1, 1], 'kos' => [1, 1], 'karpatos' => [1, 1], 'kalimnos' => [1, 1],
            'kalamata' => [1, 1], 'skopelos' => [1, 1], 'skijatos' => [1, 3], 'paros' => [1, 1],
            // Turkey
            'kusadasi' => [3, 1], 'bodrum' => [2, 1], 'alanija' => [2, 1], 'kas' => [2, 1],
            'fethije' => [2, 1], 'oludeniz' => [2, 3], 'sajd' => [2, 1], 'antalija' => [2, 1],
            'kalkan' => [1, 1], 'datca' => [1, 1], 'marmaris' => [1, 1], 'cesme' => [1, 1],
            // Egypt
            'sarm_el_seik' => [2, 1], 'marsa_alam' => [2, 1], 'dahab' => [2, 1],
            'hurgada' => [1, 1], 'el_guna' => [1, 1], 'nuvejba' => [1, 1], 'taba' => [1, 1],
            'safaga' => [1, 1], 'soma_bej' => [1, 1],
            // Cyprus
            'pafos' => [3, 1], 'ajia_napa' => [0, 2], 'larnaka' => [1, 1],
            // Malta
            // Mellieħa corrected 2026-08-17 (owner's ground-truth): best beach ON Malta, but that's
            // a low bar — average by the campaign's cross-country "great beaches" standard. Real
            // draw is its actual history (Popeye Village, WWII-era Red Tower, the Sanctuary).
            'melieha' => [2, 1], 'sliema' => [1, 0], 'st_julians' => [0, 0],
            // Tunisia
            'susa' => [2, 1], 'djerba' => [2, 1], 'monastir' => [1, 1], 'mahdija' => [1, 1],
            'tabarka' => [1, 0], 'bizerta' => [1, 0], 'hamamet' => [1, 1], 'nabel' => [1, 0],
            'sfaks' => [1, 0], 'zarzis' => [0, 1],
            // Spain (Canaries)
            'tenerife' => [3, 1], 'lansarote' => [3, 1], 'gran_kanarija' => [2, 2], 'fuerteventura' => [1, 2],
            // Croatia
            'split' => [3, 1], 'dubrovnik' => [3, 1], 'hvar' => [1, 1],
            // Portugal
            'lagos' => [2, 2], 'albufeira' => [0, 1], 'faro' => [1, 1],
            // Italy
            'taormina' => [3, 2], 'kaljari' => [2, 1], 'lampedusa' => [1, 3], 'linosa' => [1, 1],
            // Cape Verde — Santa Maria: world-class kitesurfing/windsurfing + regular whale
            // shark/manta ray diving (exploration 2), Praia de Santa Maria is genuinely
            // world-famous (beach 3). Sal Rei: major loggerhead turtle nesting site + a famous
            // shipwreck photo spot (exploration 2), vast beaches routinely on "world's best"
            // lists (beach 3).
            'santa_marija' => [2, 3], 'sal_rej' => [2, 3],
        ];

        foreach ($ratings as $slug => [$explorationTier, $beachTier]) {
            $city = TaxonomyNode::where('type', 'city')->where('slug', $slug)->first();
            if (! $city) {
                continue;
            }

            $newTags = [];
            if ($explorationTier >= 2) {
                $newTags[] = 'van_utabanih_staza';
            }
            if ($beachTier >= 2) {
                $newTags[] = 'lepe_plaze';
            }
            if (empty($newTags)) {
                continue;
            }

            $meta = $city->meta ?? [];
            $meta['atmosphere'] = array_values(array_unique([...($meta['atmosphere'] ?? []), ...$newTags]));
            $city->update(['meta' => $meta]);
        }
    }

    /**
     * `romanticno` — owner's ask, 2026-08-13 ("za Couple nemamo ni jedan romanticarski index").
     * Same tier>=2-only standard, sourced from: (a) vibe_profile descriptions already written
     * above that explicitly use the word "romantic"/"couples", (b) general-knowledge sunset/
     * scenery reputation (Santorini's caldera sunset is the textbook Mediterranean example),
     * (c) a hard exclusion — anything already carrying `zivahna_nocna_zabava` is skipped
     * regardless of scenery, since party and romance don't co-exist as a destination's PRIMARY
     * character (matches the same mixed-identity reasoning used for the rave tag itself).
     */
    private function seedRomanticTags(): void
    {
        // krit/paros added 2026-08-13 after a real cross-check (WebSearch against current
        // "most romantic Greek islands" lists) — both showed up repeatedly with specific,
        // non-generic reasoning (Crete's varied scenery/hidden coves, Paros's "candlelit
        // cocktails, secret beaches"). Mykonos deliberately still excluded despite ALSO showing
        // up on every list — it's already zivahna_nocna_zabava, and the party/romance exclusion
        // rule stays consistent rather than making a one-off exception for it.
        $tierTwoPlus = ['santorini', 'taormina', 'dubrovnik', 'rodos', 'pafos', 'simi', 'krit', 'paros'];

        $raveSlugs = TaxonomyNode::whereIn('type', ['city', 'country'])
            ->get()
            ->filter(fn (TaxonomyNode $n) => in_array('zivahna_nocna_zabava', $n->meta['atmosphere'] ?? [], true))
            ->pluck('slug');

        foreach ($tierTwoPlus as $slug) {
            if ($raveSlugs->contains($slug)) {
                continue;
            }

            $city = TaxonomyNode::where('type', 'city')->where('slug', $slug)->first();
            if (! $city) {
                continue;
            }

            $meta = $city->meta ?? [];
            $meta['atmosphere'] = array_values(array_unique([...($meta['atmosphere'] ?? []), 'romanticno']));
            $city->update(['meta' => $meta]);
        }
    }

    /**
     * Closes a real data gap caught 2026-08-13: `mirno_i_tiho` (implied by the Chillseeker
     * persona) and `porodicna_atmosfera` (a directly pickable preference_tag, also suggested by
     * group_type=porodica) both had correct persona/group_type -> preference_tag relations, but
     * zero destinations anywhere carried either slug in their meta.atmosphere — so neither could
     * ever match anything, silently.
     *
     * Deliberately NOT a fresh research pass — `seedCityAndCountryVibeProfiles()`'s good_for/
     * avoid_for fields (hover-text only today, per that method's own docblock) already encode
     * exactly this judgment call per city, written earlier this project with real per-place
     * reasoning. Cross-checked against a live WebSearch ("best family-friendly Mediterranean
     * countries/cities for kids", Malta specifically) before trusting it — matched: Hurghada/
     * Sharm El Sheikh, Hammamet, and the Lara/Belek half of Antalya all independently confirmed
     * as real family draws, and Malta confirmed genuinely MIXED (limited sandy beaches island-
     * wide) rather than uniformly family-friendly — which is exactly why this is a CITY-level
     * pass, not a country-level one: owner's explicit catch, "Malta za Kids je samo Melieha" —
     * only Mellieħa, not St. Julian's or Sliema, actually earns it. Same hard exclusion as
     * romanticno: nothing already `zivahna_nocna_zabava` qualifies for either tag (a rave strip
     * isn't quiet, and none of these vibe_profiles market themselves as family-safe either).
     *
     * mirno_i_tiho: every city whose vibe_profile good_for includes 'flegma' (Chillseeker),
     * plus pafos/krf — both explicitly say "quiet evenings"/"most of Corfu is quiet" in prose
     * despite not using the flegma tag mechanically.
     * porodicna_atmosfera: every city whose vibe_profile prose explicitly says "family" (not a
     * guess from budget/persona — the actual words are there: "built for families", "family
     * package-holiday territory", "deeply family-oriented", etc).
     */
    private function seedFamilyAndQuietTags(): void
    {
        $quietSlugs = ['marsa_alam', 'larnaka', 'melieha', 'lansarote', 'fuerteventura', 'lampedusa', 'linosa', 'pafos', 'krf', 'sal_rej'];
        $familySlugs = ['hurgada', 'sarm_el_seik', 'hamamet', 'monastir', 'antalija', 'kos', 'melieha', 'alanija', 'pafos', 'santa_marija', 'sal_rej'];

        $raveSlugs = TaxonomyNode::whereIn('type', ['city', 'country'])
            ->get()
            ->filter(fn (TaxonomyNode $n) => in_array('zivahna_nocna_zabava', $n->meta['atmosphere'] ?? [], true))
            ->pluck('slug');

        $tagsBySlug = [];
        foreach ($quietSlugs as $slug) {
            $tagsBySlug[$slug][] = 'mirno_i_tiho';
        }
        foreach ($familySlugs as $slug) {
            $tagsBySlug[$slug][] = 'porodicna_atmosfera';
        }

        foreach ($tagsBySlug as $slug => $tags) {
            if ($raveSlugs->contains($slug)) {
                continue;
            }

            $city = TaxonomyNode::where('type', 'city')->where('slug', $slug)->first();
            if (! $city) {
                continue;
            }

            $meta = $city->meta ?? [];
            $meta['atmosphere'] = array_values(array_unique([...($meta['atmosphere'] ?? []), ...$tags]));
            $city->update(['meta' => $meta]);
        }
    }

    /**
     * Owner's catch, 2026-08-12: `van_utabanih_staza`/`lepe_plaze`/`zivahna_nocna_zabava`/
     * `romanticno` only ever got written onto CITIES above (correctly — a beach, an old town, a
     * party strip, or a romantic setting is a per-place thing, not "the whole country"), which
     * meant selecting e.g. "Great beaches" or "Lively nightlife" at the COUNTRY step could never
     * match anything at all, silently — caught live: Malta showed under "Great beaches" but not
     * "Lively nightlife" despite St. Julian's being a real, standout rave city. Fix: if a country
     * has ANY child city carrying one of these tags, the country itself also gets it — a derived
     * signal ("this country HAS a real nightlife scene, somewhere in it"), not a manually-judged
     * one. Deliberately scoped to just these four tags, not a blanket "any city tag becomes a
     * country tag" rule — `pivo`/`vino`/`dobra_hrana` are already seeded directly at country
     * level with their own reasoning (see seedSwimAtmosphereTags) and must not be silently
     * overwritten or duplicated by this pass.
     */
    private function propagateCityAtmosphereToCountry(): void
    {
        $propagatedTags = [
            'van_utabanih_staza', 'lepe_plaze', 'zivahna_nocna_zabava', 'romanticno',
            // Added 2026-08-13 alongside seedFamilyAndQuietTags() — same "country HAS a spot
            // with this character, somewhere in it" derived signal, not a blanket claim. This is
            // also why Malta getting `porodicna_atmosfera` here is correct, not an over-broad
            // regression of "Malta za Kids je samo Melieha": the country tag only ever means
            // "at least one real city in it qualifies," and Mellieħa does.
            'mirno_i_tiho', 'porodicna_atmosfera',
        ];

        $countries = TaxonomyNode::where('type', 'country')->with('children')->get();

        foreach ($countries as $country) {
            $tagsPresent = collect();
            foreach ($country->children as $city) {
                $tagsPresent = $tagsPresent->merge($city->meta['atmosphere'] ?? []);
            }

            $toAdd = $tagsPresent->intersect($propagatedTags)->unique();
            if ($toAdd->isEmpty()) {
                continue;
            }

            $meta = $country->meta ?? [];
            $meta['atmosphere'] = array_values(array_unique([...($meta['atmosphere'] ?? []), ...$toAdd->values()->all()]));
            $country->update(['meta' => $meta]);
        }
    }

    /**
     * Monthly season_tier per swim country — see AccommodationPriceEstimator /
     * wizard_architecture memory, 2026-08-03. No usable free dataset exists for this (checked:
     * Eurostat's accommodation HICP is a year-over-year inflation index, not a within-year
     * seasonal profile) — `manual_estimate`, reasoned from general Mediterranean tourism
     * seasonality, same convention as hospitality/cultural_availability.
     *
     * Two templates cover 9 of the 10 countries (later swim-season start for the cooler-water
     * Adriatic/Aegean/Atlantic group vs. the warmer group with a longer season either side of
     * peak). Egipat gets its own inverted template — Red Sea resorts are a well-established
     * "winter sun" charter destination (mild winters, uncomfortably hot summers), so ITS peak
     * season is Dec-Feb, not Jun-Aug like the rest.
     */
    private function seedAccommodationSeasons(): void
    {
        $templates = [
            // grcka/hrvatska/italija/turska/portugalija: swims Jun-Sep, peak Jul-Aug
            'standard_med' => [
                1 => 'van_sezone', 2 => 'van_sezone', 3 => 'van_sezone', 4 => 'van_sezone',
                5 => 'pred_post_sezona', 6 => 'sezona', 7 => 'sezona', 8 => 'sezona',
                9 => 'sezona', 10 => 'pred_post_sezona', 11 => 'van_sezone', 12 => 'van_sezone',
            ],
            // kipar/malta/spanija/tunis: warmer water, swimmable season starts/ends earlier/later
            'warm_med' => [
                1 => 'van_sezone', 2 => 'van_sezone', 3 => 'van_sezone', 4 => 'pred_post_sezona',
                5 => 'pred_post_sezona', 6 => 'sezona', 7 => 'sezona', 8 => 'sezona',
                9 => 'sezona', 10 => 'pred_post_sezona', 11 => 'pred_post_sezona', 12 => 'van_sezone',
            ],
            // egipat: Red Sea "winter sun" destination — peak is Dec-Feb, summer is off-season
            'winter_sun' => [
                1 => 'sezona', 2 => 'sezona', 3 => 'pred_post_sezona', 4 => 'pred_post_sezona',
                5 => 'van_sezone', 6 => 'van_sezone', 7 => 'van_sezone', 8 => 'van_sezone',
                9 => 'van_sezone', 10 => 'pred_post_sezona', 11 => 'pred_post_sezona', 12 => 'sezona',
            ],
        ];

        $countryTemplate = [
            'grcka' => 'standard_med', 'hrvatska' => 'standard_med', 'italija' => 'standard_med',
            'turska' => 'standard_med', 'portugalija' => 'standard_med',
            'kipar' => 'warm_med', 'malta' => 'warm_med', 'spanija' => 'warm_med', 'tunis' => 'warm_med',
            // Real peak season is Nov-Apr (~25C when Europe is cold) — same winter-sun shape as
            // Egypt's Red Sea, confirmed via research 2026-08-19, not just assumed by geography.
            'egipat' => 'winter_sun', 'zelenortska_ostrva' => 'winter_sun',
        ];

        foreach ($countryTemplate as $slug => $templateKey) {
            $country = TaxonomyNode::where('type', 'country')->where('slug', $slug)->first();
            if (! $country) {
                continue;
            }

            $rows = [];
            foreach ($templates[$templateKey] as $month => $tier) {
                $rows[] = [
                    'taxonomy_node_id' => $country->id, 'month' => $month, 'season_tier' => $tier,
                    'source' => 'manual_estimate', 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            TaxonomyNodeAccommodationSeason::where('taxonomy_node_id', $country->id)->delete();
            TaxonomyNodeAccommodationSeason::insert($rows);
        }

        // Orthodox-majority countries — see AccommodationPriceEstimator::easterCalendarFor().
        // Everything else defaults to 'western' when this meta key is absent.
        foreach (['grcka', 'kipar'] as $slug) {
            $country = TaxonomyNode::where('type', 'country')->where('slug', $slug)->first();
            if ($country) {
                $country->update(['meta' => [...($country->meta ?? []), 'easter_calendar' => 'orthodox']]);
            }
        }
    }

    /**
     * Global holiday price-spike windows — see AccommodationPriceEstimator, 2026-08-03.
     * Deliberately just three for v1 (owner's explicit call): Christmas/New Year, Easter,
     * and May 1st. A "late September" central-European bridging holiday and US 4th-of-July
     * were both discussed and explicitly deferred, not forgotten.
     */
    private function seedHolidayPricingWindows(): void
    {
        $windows = [
            [
                'key' => 'christmas_newyear', 'label' => 'Božić / Nova godina',
                'month' => 12, 'day' => 24, 'is_easter_based' => false,
                'window_before_days' => 0, 'window_after_days' => 9, // Dec 24 -> Jan 2
                'price_multiplier' => 3.5, 'source' => 'manual_estimate',
            ],
            [
                'key' => 'easter', 'label' => 'Uskrs',
                'month' => null, 'day' => null, 'is_easter_based' => true,
                'window_before_days' => 3, 'window_after_days' => 1, // long weekend before, Easter Monday after
                'price_multiplier' => 2.2, 'source' => 'manual_estimate',
            ],
            [
                'key' => 'may_day', 'label' => 'Prvi maj',
                'month' => 5, 'day' => 1, 'is_easter_based' => false,
                'window_before_days' => 3, 'window_after_days' => 3, // catches weekend bridging either direction
                'price_multiplier' => 1.8, 'source' => 'manual_estimate',
            ],
        ];

        foreach ($windows as $window) {
            $key = $window['key'];
            unset($window['key']);
            HolidayPricingWindow::updateOrCreate(['key' => $key], $window);
        }
    }

    private function seedHolidays(): void
    {
        $ceska = TaxonomyNode::where('type', 'country')->where('slug', 'ceska')->firstOrFail();
        $belgija = TaxonomyNode::where('type', 'country')->where('slug', 'belgija')->firstOrFail();

        // Both countries' holidays are always written together as one unit in this method —
        // delete-and-reinsert scoped to just these two country ids, not a blanket truncate.
        Holiday::whereIn('country_taxonomy_node_id', [$ceska->id, $belgija->id])->delete();

        Holiday::insert([
            ['country_taxonomy_node_id' => $ceska->id, 'name' => 'Christmas', 'date' => '2026-12-25', 'created_at' => now(), 'updated_at' => now()],
            ['country_taxonomy_node_id' => $ceska->id, 'name' => 'New Year', 'date' => '2027-01-01', 'created_at' => now(), 'updated_at' => now()],
            ['country_taxonomy_node_id' => $belgija->id, 'name' => 'New Year', 'date' => '2027-01-01', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function seedWizardSteps(): void
    {
        $steps = [
            ['key' => 'trip_type', 'en' => 'Trip type', 'sr' => 'Tip putovanja', 'questions' => [
                ['key' => 'trip_type', 'en' => 'What kind of trip are you planning?', 'sr' => 'Kakvo putovanje planiraš?', 'input_type' => 'taxonomy_choice', 'taxonomy_type' => 'trip_type', 'session_field' => 'trip_type_id', 'allow_free_text' => true],
            ]],
            ['key' => 'broj_putnika', 'en' => 'How many adults are traveling?', 'sr' => 'Koliko odraslih putuje?', 'questions' => [
                ['key' => 'adults_count', 'en' => 'How many adults are traveling?', 'sr' => 'Koliko odraslih putuje?', 'input_type' => 'number', 'session_field' => 'adults_count'],
                ['key' => 'children_ages', 'en' => "Children's ages (if any)", 'sr' => 'Godine dece (ako ima)', 'input_type' => 'number_array', 'session_field' => 'children_ages'],
                ['key' => 'needs_crib', 'en' => 'Do you need a crib (per child ≤2 yrs)?', 'sr' => 'Treba li krevetac (po detetu ≤2 god.)?', 'input_type' => 'boolean', 'session_field' => 'needs_crib'],
                ['key' => 'number_of_rooms', 'en' => 'How many rooms do you need?', 'sr' => 'Koliko soba ti treba?', 'input_type' => 'number', 'session_field' => 'number_of_rooms'],
                ['key' => 'group_type', 'en' => 'What kind of group is this?', 'sr' => 'Kakvo je društvo?', 'input_type' => 'taxonomy_choice', 'taxonomy_type' => 'group_type', 'session_field' => 'group_type_id', 'allow_free_text' => true],
                // Moved here from the 'persona' step, 2026-08-06 (owner's call) — this is a
                // group-composition question like group_type right above it, not a traveler-type
                // one. Visibility rule unchanged (see WizardService.isQuestionVisible): exactly 2
                // adults, 0 children — now evaluated live within the SAME step as adults_count,
                // which is fine since it's all reactive client-side signals pre-submission.
                ['key' => 'relationship_type', 'en' => 'Just friends, or something more?', 'sr' => 'Par ili drugari?', 'input_type' => 'taxonomy_choice', 'taxonomy_type' => 'relationship_type', 'session_field' => 'free_text_answers.relationship_type'],
                // Owner's call, 2026-08-13: split out from meal_plan_preference so a mandatory
                // question forces a real answer up front ("vecina korisnika su idioti", won't
                // naturally think to look for "self catering" under a question titled "want
                // meals included?"). Redesigned 2026-08-14 (owner's catch) — a pure flow gate
                // now: "Local restaurants" skips meal_plan_preference entirely, "At the
                // accommodation" reveals its full picker (breakfast/half-board/full-board/
                // all-inclusive/self-catering all live there, see seedAmenities' $mealPlans).
                // Own taxonomy_type (no real Booking filter behind THIS question — it's pure
                // wizard-side flow logic, same category as group_type/relationship_type).
                ['key' => 'meal_style', 'en' => 'Where do you plan to eat?', 'sr' => 'Gde planiraš da jedeš?', 'input_type' => 'taxonomy_choice', 'taxonomy_type' => 'meal_style', 'session_field' => 'free_text_answers.meal_style', 'mandatory' => true],
                // Total trip spending budget (2026-07-30) — deliberately in the same "warm-up"
                // group as the other always-asked questions, not tied to any destination. See
                // BudgetEstimationEngine / GeographyResolver filterByBudget.
                // Mandatory (2026-08-06, owner's call: "ako nam nista ne kaze, ne mozemo nista
                // da mu vratimo ko data, a da valja") — first use of the generic `mandatory`
                // flag (see its migration's docblock); WizardComponent.canProceed() blocks
                // Proceed/the rooms-together Yes-No on this step until it's answered.
                ['key' => 'total_budget', 'en' => 'How much do you plan to spend on accommodation & food? (€)', 'sr' => 'Koliko planirate da potrošite na smeštaj i hranu? (€)', 'input_type' => 'number', 'session_field' => 'total_budget', 'mandatory' => true],
                // Owner's call, 2026-08-13: replaces AmenitySuggestionEngine's old budget-ratio
                // meal_plan guess ("all inclusive i pun pansion ne moze da se sa sigurnoscu
                // izvuce iz ostalih izbora... sta god da stavimo - moze da bude ili cu sam da
                // placam kafanama il necu da se cimam da idem do kafane") — board type is an
                // independent personal habit, not something budget/persona predicts, so just
                // ask directly instead of guessing. Own dedicated field (NOT amenities_yes,
                // which the Big-YES picker further down the flow also writes to — see
                // SearchSessionResolver's array_merge docblock: two questions sharing one
                // free_text_answers key would have the later one silently wipe out the
                // earlier one's picks, not merge). Optional — no pick just means "no hotel meal
                // plan," and only asked at all if meal_style says "eating out" (see
                // WizardService.isQuestionVisible) — someone self-catering has no use for it.
                ['key' => 'meal_plan_preference', 'en' => 'Want meals included?', 'sr' => 'Želiš li obroke uključene?', 'input_type' => 'taxonomy_multi_choice', 'taxonomy_type' => 'meal_plan', 'session_field' => 'free_text_answers.meal_plan_preference'],
            ]],
            ['key' => 'odakle_putujes', 'en' => 'Where you\'re traveling from', 'sr' => 'Odakle putuješ', 'questions' => [
                ['key' => 'home_city', 'en' => 'Which city are you traveling from?', 'sr' => 'Iz kog grada putuješ?', 'input_type' => 'taxonomy_choice', 'taxonomy_type' => 'city', 'session_field' => 'home_city_id', 'allow_free_text' => true],
            ]],
            ['key' => 'termin', 'en' => 'Timing', 'sr' => 'Termin', 'questions' => [
                ['key' => 'termin_category', 'en' => 'When are you planning to travel?', 'sr' => 'Kada planiraš put?', 'input_type' => 'taxonomy_choice', 'taxonomy_type' => 'termin_category', 'session_field' => 'termin_category'],
                ['key' => 'date_range', 'en' => 'Exact dates (optional)', 'sr' => 'Tačan datum (opciono)', 'input_type' => 'date_range', 'session_field' => 'date_from,date_to'],
            ]],
            // Two questions, mutually exclusive visibility by group size (see
            // wizard.service.ts isQuestionVisible) — 2026-07-30, owner's group-size taxonomy:
            // 'persona' (solo, singular, single-choice), 'persona_group' (2+, plural,
            // multi-choice — couples/groups/families all share this one, universal categories,
            // just asked collectively). 'relationship_type' moved to the 'broj_putnika' step,
            // 2026-08-06 (owner's call) — it's a group-composition question, not a traveler-type
            // one; see the comment next to it there.
            ['key' => 'persona', 'en' => 'Traveler type', 'sr' => 'Tip putnika', 'questions' => [
                ['key' => 'persona', 'en' => 'What kind of traveler are you?', 'sr' => 'Kakav si putnik?', 'input_type' => 'taxonomy_choice', 'taxonomy_type' => 'persona', 'session_field' => 'persona_id', 'allow_free_text' => true],
                ['key' => 'persona_group', 'en' => 'What is this crew into?', 'sr' => 'Šta ovu ekipu zanima?', 'input_type' => 'taxonomy_multi_choice', 'taxonomy_type' => 'persona', 'session_field' => 'free_text_answers.persona_tags', 'allow_free_text' => true],
            ]],
            ['key' => 'preferencije', 'en' => 'What matters to you', 'sr' => 'Šta ti je bitno', 'questions' => [
                // Relabeled 2026-08-04 (owner's call) — 'Atmosphere / Vibe' frames these tags
                // as describing the PLACE's mood, distinct from persona (the TRAVELER's type).
                ['key' => 'preference_tags', 'en' => 'Atmosphere / Vibe of this trip', 'sr' => 'Atmosfera / vajb putovanja', 'input_type' => 'taxonomy_multi_choice', 'taxonomy_type' => 'preference_tag', 'session_field' => 'free_text_answers.preference_tags', 'allow_free_text' => true],
                ['key' => 'budget_tier', 'en' => 'What is your budget per night?', 'sr' => 'Koji ti je budžet po noćenju?', 'input_type' => 'taxonomy_choice', 'taxonomy_type' => 'budget_tier', 'session_field' => 'budget_tier_id', 'allow_free_text' => true],
            ]],
            ['key' => 'zemlja_regija', 'en' => 'Country / region', 'sr' => 'Zemlja / regija', 'questions' => [
                ['key' => 'region_theme', 'en' => 'Which part of the world interests you?', 'sr' => 'Koji deo sveta te zanima?', 'input_type' => 'taxonomy_choice', 'taxonomy_type' => 'region_theme', 'session_field' => 'free_text_answers.region_theme', 'allow_free_text' => true],
                // Multi-select, 2026-08-12 (owner's ask) — was taxonomy_choice/country_region_id
                // (single FK). Nothing downstream of city selection actually reads country_region_id
                // directly (booking/budget/cultural-availability all derive country from the
                // eventually-CHOSEN city's parent, see SearchSessionQueryCompiler::resolveBudgetContext),
                // so this was safe to convert without touching the compilation pipeline.
                ['key' => 'country_region', 'en' => 'Suggested country/region', 'sr' => 'Predložena zemlja/regija', 'input_type' => 'taxonomy_multi_choice', 'taxonomy_type' => 'country', 'session_field' => 'free_text_answers.country_region_ids', 'allow_free_text' => true],
            ]],
            ['key' => 'grad', 'en' => 'City', 'sr' => 'Grad', 'questions' => [
                ['key' => 'city', 'en' => 'Pick a city', 'sr' => 'Izaberi grad', 'input_type' => 'taxonomy_choice', 'taxonomy_type' => 'city', 'session_field' => 'city_id', 'allow_free_text' => true],
            ]],
            ['key' => 'smestaj', 'en' => 'Specific accommodation', 'sr' => 'Konkretan smeštaj', 'questions' => [
                // "Big YES / Big NO" amenity picker (2026-08-04, owner's design) — a single
                // typeahead over the COMBINED tip_smestaja/accommodation_facility/room_facility/
                // meal_plan vocabulary (real Booking ht_id/hotelfacility/roomfacility/mealplan
                // IDs, seeded 2026-08-03), rendered via a dedicated widget (see
                // AmenityPickerComponent), same consolidation pattern as travelers-input. No
                // `taxonomy_type` here on purpose — the widget fetches all four types itself,
                // not the generic single-type loadGeographyForCurrentStep() path. Yes routes to
                // real Booking filters (toBookingParams), No has no Booking equivalent so it
                // routes to toHonestReportSignals only — see SearchSessionQueryCompiler.
                ['key' => 'amenities_yes', 'en' => 'What would make this place great?', 'sr' => 'Šta bi ovo mesto učinilo savršenim?', 'input_type' => 'taxonomy_multi_choice', 'session_field' => 'free_text_answers.amenities_yes'],
                ['key' => 'amenities_no', 'en' => "Anything you'd rather avoid?", 'sr' => 'Ima li nešto što bi radije izbegao?', 'input_type' => 'taxonomy_multi_choice', 'session_field' => 'free_text_answers.amenities_no'],
                // Deliberately steered toward the unusual/exotic (2026-07-30, owner: "da taj
                // opis tu fokusira na neku egzoticniju zelju da ne trosimo AI tokene da izvlaci
                // amenities") — now doubles as the Big YES/NO picker's fallback: unmatched
                // typed text (no taxonomy match) lands here instead of being lost.
                // "(...will get its own checklist soon)" hint retired 2026-08-10 — it predates
                // the Big YES/NO picker above (built 2026-08-04), which already covers exactly
                // that ("soon" promise was stale). Rewritten to point at it instead.
                ['key' => 'smestaj_preference', 'en' => "Anything unusual on your wishlist? (Regular stuff like pool or parking? Type it in the box above instead)", 'sr' => 'Ima li nešto neobično na tvojoj listi želja? (Obične stvari kao bazen ili parking? Ukucaj ih u polje iznad)', 'input_type' => 'text', 'session_field' => 'free_text_answers.smestaj_preference', 'allow_free_text' => true],
                // Hidden field, no UI of its own — the Big-NO picker's unmatched-text fallback
                // routes here instead of smestaj_preference (bug fixed 2026-08-04: both used to
                // share smestaj_preference, which reads backwards for an avoid-item — "wishlist:
                // Crowd, Loud" sounds like they're wanted). See AmenityPickerComponent/wizard.ts.
                ['key' => 'smestaj_avoid', 'en' => 'Avoid notes (internal)', 'sr' => 'Beleške za izbegavanje (interno)', 'input_type' => 'text', 'session_field' => 'free_text_answers.smestaj_avoid'],
            ]],
        ];

        foreach ($steps as $stepIndex => $stepData) {
            $step = WizardStep::updateOrCreate(
                ['key' => $stepData['key']],
                ['label' => $stepData['en'], 'sort_order' => $stepIndex],
            );
            $step->translations()->updateOrCreate(
                ['translatable_type' => WizardStep::class, 'translatable_id' => $step->id, 'field' => 'label', 'locale' => 'sr'],
                ['value' => $stepData['sr'], 'source_hash' => hash('crc32', $stepData['en']), 'status' => 'human'],
            );

            foreach ($stepData['questions'] as $qIndex => $q) {
                $question = WizardQuestion::updateOrCreate(
                    ['key' => $q['key']],
                    [
                        'wizard_step_id' => $step->id,
                        'label' => $q['en'],
                        'input_type' => $q['input_type'],
                        'taxonomy_type' => $q['taxonomy_type'] ?? null,
                        'session_field' => $q['session_field'] ?? null,
                        'allow_free_text' => $q['allow_free_text'] ?? false,
                        'mandatory' => $q['mandatory'] ?? false,
                        'sort_order' => $qIndex,
                    ],
                );
                $question->translations()->updateOrCreate(
                    ['translatable_type' => WizardQuestion::class, 'translatable_id' => $question->id, 'field' => 'label', 'locale' => 'sr'],
                    ['value' => $q['sr'], 'source_hash' => hash('crc32', $q['en']), 'status' => 'human'],
                );
            }
        }
    }

    /**
     * "Kasno letovanje" campaign (2026-07-30, see wizard_architecture "wizard tree design") —
     * the first themed entry point built on the wizard_campaigns mechanism. Presets
     * termin_category so it's never asked; skips `trip_type` (this theme was built directly
     * on termin_category, no trip_type node needed — see the 2026-07-30 ground-truth
     * correction) and `region_theme` (kasno_kupanje's own excludes already narrow geography
     * to Mediterranean-adjacent, asking "which part of the world" would be redundant/confusing
     * for a theme that's already geographically scoped).
     */
    private function seedWizardCampaigns(): void
    {
        $campaign = WizardCampaign::updateOrCreate(
            ['key' => 'kasno-letovanje'],
            [
                'label' => 'Kasno letovanje',
                'landing_headline' => 'Još malo sunca pre zime',
                'preset_answers' => ['termin_category' => 'kasno_kupanje'],
                'is_active' => true,
                'sort_order' => 0,
                // Owner's call, 2026-08-11 — the campaign's real bookable window, anchor for
                // per-week destination pricing. Saturday-to-Saturday (typical charter/package
                // check-in day) — see WizardCampaign::seasonWeeks(). 2026-08-30 was originally
                // named as the start but is actually a Sunday (verified); owner confirmed
                // 2026-08-29 (the real Saturday) instead.
                'season_start_date' => '2026-08-29',
                'season_end_date' => '2026-11-01',
                // Owner's ask, 2026-08-13: default the budget field instead of forcing everyone
                // to type — "ljudi ne vole da kucaju... vise da tipkaju". Per-campaign so a
                // future campaign (different season, different typical spend) isn't stuck with
                // these same numbers.
                //
                // Adult raised 400 -> 500, 2026-08-19 (owner's reasoning): a solo adult is going
                // out to enjoy themselves, that number stands as-is; with a child along the
                // parent typically spends LESS on themselves and MORE on the kid, so the flat
                // 2*500 + 2*300 math for a family of 4 doesn't need per-composition juggling on
                // top of it — "bilo bi bezveze da zongliramo podatke". This is a comfortable-
                // AVERAGE default, not a bare-minimum one ("ovo jeste za prosek, ne za
                // sirotinju") — DACH (this campaign's actual audience) has a higher cost-of-
                // living baseline than our own, and even a modest DACH trip needs numbers in
                // this range as a floor, not a ceiling. Always just a starting point anyway — the
                // user can freely dial it down via the +/- stepper if it reads as too generous.
                'meta' => ['default_budget_per_adult_eur' => 500, 'default_budget_per_child_eur' => 300],
            ],
        );

        $questionKeys = [
            // relationship_type sits with group_type here (not off with persona/persona_group
            // further down) — see the same move in seedWizardSteps' broj_putnika step comment,
            // 2026-08-06. This campaign's question order is a SEPARATE pivot
            // (campaign_questions.sort_order) from the generic WizardQuestion.wizard_step_id
            // grouping, and the two don't stay in sync automatically — bug caught live by the
            // owner: moving the question in seedWizardSteps alone left it still rendering as an
            // orphaned second "Number of travelers" bubble here, out of order, since this
            // campaign's flow is driven by THIS array, not that grouping.
            'adults_count', 'children_ages', 'needs_crib', 'number_of_rooms', 'group_type', 'relationship_type',
            // Added 2026-08-13 — same "this campaign's order is its own pivot, not the generic
            // wizard_step_id grouping" gotcha noted above: had to be added here explicitly too,
            // or it silently never renders in the live kasno-letovanje flow at all.
            'meal_style', 'total_budget', 'meal_plan_preference',
            'home_city',
            'date_range',
            'persona', 'persona_group',
            // budget_tier (accommodation price/night) deliberately excluded from this campaign
            // — redundant with total_budget, already asked above (owner's call, 2026-07-30:
            // "korak 11 suvisan, vec si me pitao za budzet"). Booking's filters.price simply
            // stays absent for this campaign's sessions — no code change needed, see
            // SearchSessionQueryCompiler's "absent = no error" convention.
            'preference_tags',
            // Big YES/NO + wishlist now come BEFORE country/city — owner's call, 2026-08-04:
            // all of "screen 1" (the chat-scroll Q&A) finishes first, THEN a calculating
            // transition, THEN "screen 2" (fancy country/city cards) — see wizard_architecture
            // "FINAL WORKFLOW DESIGN". Previously these sat after city, which no longer matches
            // the two-screen shape.
            'amenities_yes', 'amenities_no',
            'smestaj_preference', 'smestaj_avoid',
            'country_region',
            'city',
        ];

        $syncData = [];
        foreach ($questionKeys as $i => $key) {
            $question = WizardQuestion::where('key', $key)->firstOrFail();
            $syncData[$question->id] = ['sort_order' => $i];
        }
        $campaign->questions()->sync($syncData);
    }

    /**
     * Started as minimal, mechanism-proving relations only (owner's early steer: "nebitne su
     * persone... fokus na vezama"). Grew real content 2026-08-10 with the persona <->
     * preference_tag vibe-matching pairs below — still not meant to be exhaustive, just the
     * pairs with an actual logical relationship.
     */
    private function seedRelations(): void
    {
        $this->relate('trip_type', 'city_break', 'excludes', 'termin_category', 'letovanje');
        $this->relate('trip_type', 'city_break', 'excludes', 'termin_category', 'zimovanje');
        $this->relate('persona', 'gurman', 'implies', 'preference_tag', 'dobra_hrana');
        $this->relate('preference_tag', 'jeftino', 'suggests', 'budget_tier', 'do_20e');

        // Persona <-> preference_tag ("Atmosphere / Vibe") vibe-matching, owner's call
        // 2026-08-10: fills the gap where e.g. "Partygoer" was showing "Family-friendly
        // atmosphere" as a live option (see wizard_architecture chat, "kao iz 2 sveta da su").
        // implies = definitional trait for that persona; excludes = genuine vibe contradiction.
        // Deliberately NOT touching pivo/vino for partijaner — partying doesn't imply drinking
        // (owner's call: "neki partijaju na Ekserima") — nor the cultural-availability tags
        // (halal/vegan/lgbt/alkohol) for any persona, since those are personal/cultural
        // requirements independent of trip vibe. Not exhaustive — only the pairs with a real
        // logical relationship, everything else stays freely selectable.
        $this->relate('persona', 'istrazivac', 'implies', 'preference_tag', 'van_utabanih_staza');
        $this->relate('persona', 'partijaner', 'implies', 'preference_tag', 'zivahna_nocna_zabava');
        $this->relate('persona', 'partijaner', 'excludes', 'preference_tag', 'porodicna_atmosfera');
        $this->relate('persona', 'partijaner', 'excludes', 'preference_tag', 'mirno_i_tiho');
        $this->relate('persona', 'flegma', 'implies', 'preference_tag', 'mirno_i_tiho');
        $this->relate('persona', 'flegma', 'excludes', 'preference_tag', 'zivahna_nocna_zabava');

        // Owner's call, 2026-08-03: "porodica ne moz da bude partigoer" — filters 'partijaner'
        // out of persona_group's options once group_type=porodica is selected. Uses the SAME
        // excludes mechanism GeographyResolver already applies generically to any type, not a
        // new one — persona_group's options query is just another suggestedGeography(type)
        // call, so this needed zero new code.
        $this->relate('group_type', 'porodica', 'excludes', 'persona', 'partijaner');

        // Owner's call, 2026-08-04 ("da l su penzioneri i raveri iskljucivi :D") — same
        // pattern, different group. Not exhaustive; more pairs like this belong in Filament's
        // Excludes tab (TaxonomyNodeResource -> pick the node -> Excludes), not another seeder
        // edit — this one's here because it's an obvious, always-true pair worth locking in as
        // a demonstrated example of the pattern.
        $this->relate('group_type', 'drustvo_penzionera', 'excludes', 'persona', 'partijaner');

        // group_type -> preference_tag, 2026-08-13 (owner's ask) — deliberately `suggests`, not
        // `implies`: a pre-checked, freely-editable nudge, not a locked one. Owner's own framing
        // ("baksuz kontraš koji kaže oću bre da divljam" — the contrarian retiree who wants to
        // rave anyway) — group composition is a REASONABLE default, not a hard rule about what a
        // family/retiree group is allowed to want. `klub` (Club/sports team) deliberately left
        // unwired — needs real research (Spa? Team building? Corporate) parked for a v2 backlog,
        // not guessed at here. See WizardService.syncAnswersFromSession for the frontend half
        // that makes a `suggests`'d preference_tag actually show up checked, not just silently
        // count toward match_score.
        $this->relate('group_type', 'porodica', 'suggests', 'preference_tag', 'porodicna_atmosfera');
        $this->relate('group_type', 'drustvo_penzionera', 'suggests', 'preference_tag', 'mirno_i_tiho');
        $this->relate('group_type', 'grupa_prijatelja', 'suggests', 'preference_tag', 'zivahna_nocna_zabava');

        // relationship_type -> preference_tag, 2026-08-13 (owner's ask: "za Couple nemamo ni
        // jedan romanticarski index") — same `suggests` (soft, editable) pattern as group_type
        // above.
        $this->relate('relationship_type', 'par', 'suggests', 'preference_tag', 'romanticno');

        // Proves the seasonal_window mechanism end-to-end (Greece is a summer-beach country in
        // the current seed data) — not an exhaustive seasonality dataset, see wizard_architecture.
        $this->relate('country', 'grcka', 'seasonal_window', 'termin_category', 'letovanje', ['months' => [6, 7, 8, 9]]);

        // "Još malo sunca" themed entry point (2026-07-14) — excludes are cheap here (7 edges)
        // vs. implying/suggesting all 32 swim cities individually. istocna_evropa/zapadna_evropa
        // are excluded at region_theme level since ALL their countries (ceska, belgija, srbija)
        // are non-swim — but anticki_svet is NOT excluded, since grcka/italija are MIXED
        // (Atina/Rim are city-break, but Krit/Rodos/Taormina/... are swim) — filtering those two
        // happens at the city level instead, one level down.
        $this->relate('termin_category', 'kasno_kupanje', 'excludes', 'region_theme', 'istocna_evropa');
        $this->relate('termin_category', 'kasno_kupanje', 'excludes', 'region_theme', 'zapadna_evropa');
        $this->relate('termin_category', 'kasno_kupanje', 'excludes', 'country', 'ceska');
        $this->relate('termin_category', 'kasno_kupanje', 'excludes', 'country', 'belgija');
        $this->relate('termin_category', 'kasno_kupanje', 'excludes', 'country', 'srbija');
        // Owner's call, 2026-08-05: "izbaci Hrvatsku za septembar, ladno je" — confirmed by our
        // own climate data (Split/Dubrovnik/Hvar already noticeably coolest of the 10 swim
        // countries by November, see seedSwimDestinations' comment). Excluded outright rather
        // than just skipped in price entry — this campaign should never suggest it at all.
        $this->relate('termin_category', 'kasno_kupanje', 'excludes', 'country', 'hrvatska');
        $this->relate('termin_category', 'kasno_kupanje', 'excludes', 'city', 'atina');
        $this->relate('termin_category', 'kasno_kupanje', 'excludes', 'city', 'rim');

        // weighted_toward proof examples — deliberately from persona/preference_tag, NOT
        // tip_smestaja (accommodation type is still unseeded, waiting on real Booking IDs, see
        // seedBudgetTiers). A persona keeps its own weight regardless of what else is selected
        // (e.g. Gurman in a hostel still cares about restaurant prices) — see wizard_architecture.
        $this->relate('persona', 'gurman', 'weighted_toward', 'cost_category', 'hospitality', ['weight' => 3]);
        $this->relate('persona', 'partijaner', 'weighted_toward', 'cost_category', 'hospitality', ['weight' => 2]);
        $this->relate('persona', 'istrazivac', 'weighted_toward', 'cost_category', 'transport', ['weight' => 2]);
        $this->relate('preference_tag', 'jeftino', 'weighted_toward', 'cost_category', 'local_stores', ['weight' => 3]);
        $this->relate('preference_tag', 'kvalitet', 'weighted_toward', 'cost_category', 'hospitality', ['weight' => 2]);
    }

    /**
     * relationship method name doesn't always match the raw relation_type string (implies/
     * suggests/excludes happen to be identical; seasonal_window's relationship is plural
     * `seasonalWindows()`) — mapped explicitly rather than assuming a naming convention.
     */
    private const RELATIONSHIP_METHODS = [
        'implies' => 'implies',
        'suggests' => 'suggests',
        'excludes' => 'excludes',
        'seasonal_window' => 'seasonalWindows',
        'weighted_toward' => 'weightedToward',
    ];

    private function relate(string $fromType, string $fromSlug, string $relationType, string $toType, string $toSlug, ?array $meta = null): void
    {
        $from = TaxonomyNode::where('type', $fromType)->where('slug', $fromSlug)->firstOrFail();
        $to = TaxonomyNode::where('type', $toType)->where('slug', $toSlug)->firstOrFail();

        $method = self::RELATIONSHIP_METHODS[$relationType];

        $existing = TaxonomyNodeRelation::where('from_taxonomy_node_id', $from->id)
            ->where('to_taxonomy_node_id', $to->id)
            ->where('relation_type', $relationType)
            ->first();

        if ($existing) {
            if ($existing->meta !== $meta) {
                $existing->update(['meta' => $meta]);
            }

            return;
        }

        $from->{$method}()->attach($to->id, [
            'relation_type' => $relationType,
            'meta' => $meta === null ? null : json_encode($meta),
        ]);
    }

    /**
     * German translations — DACH market (Booking Affiliate region, see CLAUDE.md section 8),
     * added 2026-08-11. Separate pass rather than threading a 4th 'de' param through every
     * node()/step/question call site (100+ of them) — this reads the same canonical English
     * `label` the app already has and writes one 'de' Translation row per entry, exactly the
     * same mechanism node() already uses for 'sr'.
     *
     * City proper nouns are deliberately left untranslated (no row written = falls back to the
     * canonical English label) EXCEPT the handful with an actual, well-known German exonym
     * (Athen, Belgrad, Brügge, Prag, Rom, Korfu, Rhodos, Kreta) — inventing German place names
     * for the rest risked being wrong, not just untranslated.
     */
    private function seedGermanTranslations(): void
    {
        $byType = [
            'trip_type' => [
                'city_break' => 'Städtereise',
                'snow' => 'Schnee',
                'summer_sea' => 'Sommer / Meer',
            ],
            'group_type' => [
                'porodica' => 'Familie',
                'skola' => 'Klassenfahrt',
                'klub' => 'Sonstiges',
                'drustvo_penzionera' => 'Rentnergruppe',
                'grupa_prijatelja' => 'Größere Freundesgruppe',
            ],
            'relationship_type' => [
                'par' => 'Paar',
                'drugari' => 'Freunde',
                'rodbina' => 'Verwandte',
            ],
            'persona' => [
                'istrazivac' => 'Entdecker',
                'partijaner' => 'Partytier',
                'gurman' => 'Feinschmecker',
                'flegma' => 'Genießer — einfach entspannen',
            ],
            'preference_tag' => [
                'jeftino' => 'Günstig',
                'kvalitet' => 'Qualität vor Preis',
                'pivo' => 'Gutes Bier',
                'vino' => 'Guter Wein',
                'dobra_hrana' => 'Gutes Essen',
                'zivahna_nocna_zabava' => 'Lebhaftes Nachtleben',
                'mirno_i_tiho' => 'Ruhig & friedlich',
                'van_utabanih_staza' => 'Abseits ausgetretener Pfade',
                'lepe_plaze' => 'Schöne Strände',
                'romanticno' => 'Romantische Atmosphäre',
                'porodicna_atmosfera' => 'Familienfreundliche Atmosphäre',
                'zeli_alkohol_slobodno' => 'Freier Zugang zu Alkohol gewünscht',
                'zeli_halal' => 'Halal-Optionen gewünscht',
                'zeli_vegan' => 'Vegane Optionen gewünscht',
                'zeli_lgbt_friendly' => 'LGBT-freundliches Reiseziel gewünscht',
            ],
            'budget_tier' => [
                'do_20e' => 'Bis 20€/Nacht',
                '20_50e' => '20-50€/Nacht',
                '50_100e' => '50-100€/Nacht',
                '100e_plus' => '100€+/Nacht',
            ],
            'region_theme' => [
                'istocna_evropa' => 'Osteuropa',
                'zapadna_evropa' => 'Westeuropa',
                'anticki_svet' => 'Antike Welt',
                'mediteran' => 'Mittelmeerraum',
            ],
            'termin_category' => [
                'letovanje' => 'Sommerurlaub',
                'zimovanje' => 'Winterurlaub',
                'praznici' => 'Zu den Feiertagen',
                'vikend_break' => 'Wochenendtrip',
                'sledeca_nedelja' => 'Nächste Woche',
                'sledeci_mesec' => 'Nächster Monat',
                'sledeca_sezona' => 'Nächste Saison',
                'znam_tacno_datum' => 'Ich kenne das genaue Datum!',
                'kasno_kupanje' => 'Noch eine Woche Sonne',
            ],
            'tip_smestaja' => [
                'hotel' => 'Hotel',
                'apartman' => 'Apartment',
                'vila' => 'Villa',
                'holiday_home' => 'Ferienhaus',
                'guest_house' => 'Gästehaus',
                'chalet' => 'Chalet',
            ],
            'accommodation_facility' => [
                'bazen' => 'Schwimmbad',
                'plaza' => 'Strandlage',
                'parking' => 'Parkplatz',
                'wifi' => 'Kostenloses WLAN',
                'spa' => 'Spa & Wellnessbereich',
                'restoran' => 'Restaurant',
                'usluga_u_sobu' => 'Zimmerservice',
                'recepcija_24h' => '24-Stunden-Rezeption',
                'teretana' => 'Fitnesscenter',
                'sobe_za_nepusace' => 'Nichtraucherzimmer',
                'aerodromski_prevoz' => 'Flughafentransfer',
                'djakuzi' => 'Whirlpool',
                'pristupacnost_kolica' => 'Rollstuhlgerecht',
            ],
            'room_facility' => [
                'klima' => 'Klimaanlage',
                'privatno_kupatilo' => 'Eigenes Badezimmer',
                'privatni_bazen' => 'Privatpool',
                'pogled_na_more' => 'Meerblick',
                'balkon' => 'Balkon',
                'kuhinja' => 'Küche/Kochnische',
                'vesmasina' => 'Waschmaschine',
                'frizider' => 'Kühlschrank',
                'terasa' => 'Terrasse',
            ],
            'meal_plan' => [
                'dorucak' => 'Frühstück inklusive',
                'dorucak_rucak' => 'Frühstück & Mittagessen inklusive',
                'dorucak_vecera' => 'Frühstück & Abendessen inklusive',
                'pun_pansion' => 'Alle Mahlzeiten inklusive',
                'sve_ukljuceno' => 'All-inclusive',
            ],
            'meal_style' => [
                'jede_napolju' => 'Lokale Restaurants',
                'u_smestaju' => 'In der Unterkunft',
                'sam_se_snalazim' => 'Ich organisiere mich selbst (Selbstverpflegung)',
            ],
            'cost_category' => [
                'hospitality' => 'Gastronomie (Essen/Trinken auswärts)',
                'local_stores' => 'Lebensmittelgeschäfte (Selbstversorgung)',
                'transport' => 'Örtlicher Transport',
            ],
            'country' => [
                'hrvatska' => 'Kroatien',
                'malta' => 'Malta',
                'egipat' => 'Ägypten',
                'portugalija' => 'Portugal',
                'spanija' => 'Spanien',
                'turska' => 'Türkei',
                'ceska' => 'Tschechien',
                'tunis' => 'Tunesien',
                'kipar' => 'Zypern',
                'belgija' => 'Belgien',
                'italija' => 'Italien',
                'grcka' => 'Griechenland',
                'srbija' => 'Serbien',
                'zelenortska_ostrva' => 'Kap Verde',
            ],
            // Only the handful with a real, well-known German exonym — everything else falls
            // back to its canonical English (== proper noun) label, see method docblock.
            'city' => [
                'atina' => 'Athen',
                'beograd' => 'Belgrad',
                'brugge' => 'Brügge',
                'prag' => 'Prag',
                'rim' => 'Rom',
                'krf' => 'Korfu',
                'rodos' => 'Rhodos',
                'krit' => 'Heraklion (Kreta)',
            ],
        ];

        foreach ($byType as $type => $labels) {
            foreach ($labels as $slug => $labelDe) {
                $node = TaxonomyNode::where('type', $type)->where('slug', $slug)->first();
                if (! $node) {
                    continue;
                }

                $node->translations()->updateOrCreate(
                    ['translatable_type' => TaxonomyNode::class, 'translatable_id' => $node->id, 'field' => 'label', 'locale' => 'de'],
                    ['value' => $labelDe, 'source_hash' => hash('crc32', $node->label), 'status' => 'human'],
                );
            }
        }

        $stepLabels = [
            'trip_type' => 'Reiseart',
            'broj_putnika' => 'Wie viele Erwachsene reisen?',
            'odakle_putujes' => 'Von wo reist du?',
            'termin' => 'Zeitraum',
            'persona' => 'Reisetyp',
            'preferencije' => 'Was ist dir wichtig',
            'zemlja_regija' => 'Land / Region',
            'grad' => 'Stadt',
            'smestaj' => 'Konkrete Unterkunftswünsche',
        ];

        foreach ($stepLabels as $key => $labelDe) {
            $step = WizardStep::where('key', $key)->first();
            if (! $step) {
                continue;
            }

            $step->translations()->updateOrCreate(
                ['translatable_type' => WizardStep::class, 'translatable_id' => $step->id, 'field' => 'label', 'locale' => 'de'],
                ['value' => $labelDe, 'source_hash' => hash('crc32', $step->label), 'status' => 'human'],
            );
        }

        $questionLabels = [
            'trip_type' => 'Was für eine Reise planst du?',
            'adults_count' => 'Wie viele Erwachsene reisen?',
            'children_ages' => 'Alter der Kinder (falls vorhanden)',
            'needs_crib' => 'Brauchst du ein Babybett (pro Kind ≤2 Jahre)?',
            'number_of_rooms' => 'Wie viele Zimmer brauchst du?',
            'group_type' => 'Was für eine Gruppe seid ihr?',
            'home_city' => 'Aus welcher Stadt reist du an?',
            'termin_category' => 'Wann planst du zu reisen?',
            'date_range' => 'Genaues Datum (optional)',
            'persona' => 'Was für ein Reisetyp bist du?',
            'persona_group' => 'Wofür interessiert sich diese Gruppe?',
            'preference_tags' => 'Atmosphäre / Stimmung dieser Reise',
            'budget_tier' => 'Was ist dein Budget pro Nacht?',
            'region_theme' => 'Welcher Teil der Welt interessiert dich?',
            'country_region' => 'Vorgeschlagenes Land/Region',
            'city' => 'Wähle eine Stadt',
            'amenities_yes' => 'Was würde diesen Ort perfekt machen?',
            'amenities_no' => 'Gibt es etwas, das du lieber vermeiden möchtest?',
            'smestaj_avoid' => 'Hinweise zum Vermeiden (intern)',
            'relationship_type' => 'Nur Freunde, oder etwas mehr?',
            'meal_style' => 'Kochst du selbst, oder isst du auswärts?',
            'total_budget' => 'Wie viel möchtest du für Unterkunft & Verpflegung ausgeben? (€)',
            'meal_plan_preference' => 'Möchtest du Mahlzeiten inklusive?',
            'smestaj_preference' => 'Etwas Ungewöhnliches auf deiner Wunschliste? (Übliche Dinge wie Pool oder Parkplatz? Trag sie stattdessen oben ein)',
        ];

        foreach ($questionLabels as $key => $labelDe) {
            $question = WizardQuestion::where('key', $key)->first();
            if (! $question) {
                continue;
            }

            $question->translations()->updateOrCreate(
                ['translatable_type' => WizardQuestion::class, 'translatable_id' => $question->id, 'field' => 'label', 'locale' => 'de'],
                ['value' => $labelDe, 'source_hash' => hash('crc32', $question->label), 'status' => 'human'],
            );
        }

        // WizardCampaign's canonical label/landing_headline are Serbian, not English (a
        // pre-existing gap, predates this German work — not fixing that here, out of scope).
        // Also seed 'en' so English-locale visitors don't see Serbian text on the campaign
        // landing screen either, since that's a strictly better default than leaving it broken.
        $campaign = WizardCampaign::where('key', 'kasno-letovanje')->first();
        if ($campaign) {
            $campaign->translations()->updateOrCreate(
                ['translatable_type' => WizardCampaign::class, 'translatable_id' => $campaign->id, 'field' => 'label', 'locale' => 'de'],
                ['value' => 'Spätsommer-Urlaub', 'source_hash' => hash('crc32', $campaign->label), 'status' => 'human'],
            );
            $campaign->translations()->updateOrCreate(
                ['translatable_type' => WizardCampaign::class, 'translatable_id' => $campaign->id, 'field' => 'landing_headline', 'locale' => 'de'],
                ['value' => 'Noch eine Woche Sonne vor dem Winter', 'source_hash' => hash('crc32', (string) $campaign->landing_headline), 'status' => 'human'],
            );
            $campaign->translations()->updateOrCreate(
                ['translatable_type' => WizardCampaign::class, 'translatable_id' => $campaign->id, 'field' => 'label', 'locale' => 'en'],
                ['value' => 'Late Summer Getaway', 'source_hash' => hash('crc32', $campaign->label), 'status' => 'human'],
            );
            $campaign->translations()->updateOrCreate(
                ['translatable_type' => WizardCampaign::class, 'translatable_id' => $campaign->id, 'field' => 'landing_headline', 'locale' => 'en'],
                ['value' => 'One more week of sun before winter', 'source_hash' => hash('crc32', (string) $campaign->landing_headline), 'status' => 'human'],
            );
        }
    }
}
