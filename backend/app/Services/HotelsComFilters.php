<?php

namespace App\Services;

/**
 * Confirmed Hotels.com search-filter query parameters, captured directly from their own live
 * filter sidebar HTML (owner-saved pages + pasted snippets, 2026-09-08) — same "real captured
 * example, not guessed" discipline as SearchSessionQueryCompiler::toBookingUrl()'s dest_id.
 *
 * This is a REFERENCE CATALOG only — plain constants, no database table (this is Hotels.com's
 * own fixed vocabulary, not data that varies per session or needs SQL querying/joining, same
 * reasoning as BudgetEstimationEngine::MEAL_PLAN_COVERAGE_RATIOS). Listing a value here means
 * "confirmed to exist as a real parameter" — it does NOT mean "safe to wire into toHotelsUrl()
 * automatically." Real coverage varies wildly by destination (e.g. TRAVELER_TYPE's 'romantic'
 * returned zero results on BOTH Prague and Cyprus when tested live, 2026-09-08 — a real filter
 * that exists is not the same as a filter worth using). Each value should get its own live
 * spot-check on a couple of real destinations before being wired into generated URLs.
 *
 * `room_amenities_group` and `room_views_group` only appeared when searching a specific CITY
 * with real inventory behind them (e.g. a beach city for ocean_room_view/swim_up_room) — they
 * were absent from an earlier Prague-focused capture. Don't assume every category below is
 * present for every destination.
 */
class HotelsComFilters
{
    /** name="travelerType". 'romantic' confirmed BAD (zero results, Prague + Cyprus,
     *  2026-09-08) — do not use. 'lgbtq_welcoming' had real coverage (63 in Prague) and is the
     *  live candidate for the cultural_availability passthrough idea (see the
     *  sunny-swinging-hellman plan file). */
    public const TRAVELER_TYPE = [
        'adults_only',
        'luxury_property',
        'budget_property',
        'lgbtq_welcoming',
        'family_friendly',
        'romantic', // confirmed near-zero real coverage — don't use
        'eco_certified',
        'business_friendly',
        'wedding',
    ];

    /** name="amenities" — property-level (not room-level, see ROOM_AMENITIES below). */
    public const AMENITIES = [
        'AIR_CONDITIONING',
        'BALCONY_OR_TERRACE',
        'BAR',
        'CASINO',
        'CRIB',
        'ELECTRIC_CAR',
        'FREE_AIRPORT_TRANSPORTATION',
        'FREE_PARKING',
        'GOLF_COURSE',
        'GYM',
        'HOT_TUB',
        'KITCHEN_KITCHENETTE',
        'PETS',
        'POOL',
        'RESTAURANT_IN_HOTEL',
        'SPA_ON_SITE',
        'WASHER_DRYER',
        'WATER_PARK',
        'WIFI',
    ];

    /** name="room_amenities_group" — room-level, distinct from property-level AMENITIES above.
     *  Only confirmed present on a beach-city search (not the Prague capture). */
    public const ROOM_AMENITIES = [
        'ra_balcony_patio',
        'ra_air_conditioning',
        'ra_private_bathroom',
        'ra_private_pool',
        'ra_wifi',
        'ra_private_hot_tub',
        'ra_kitchen',
        'ra_accessibility_features',
        'ra_swim_up_room',
        'ra_jetted_soaking_bathtub',
        'ra_ground_floor',
        'ra_refrigerator',
        'ra_fireplace',
        'ra_blackout_curtains',
        'ra_top_floor',
        'ra_coffee_tea_maker',
        'ra_yard',
        'ra_in_room_entertainment',
        'ra_safe',
        'ra_porch_lanai',
        'ra_private_sauna',
    ];

    /** name="room_views_group" — same beach-city-only caveat as ROOM_AMENITIES.
     *  'ocean_room_view' is a real candidate for the swim/kasno-letovanje campaign specifically —
     *  "room with an actual sea view", not just "near the beach". */
    public const ROOM_VIEWS = [
        'ocean_room_view',
        'mountain_room_view',
        'bay_room_view',
        'park_room_view',
        'city_room_view',
        'garden_room_view',
        'courtyard_room_view',
        'lake_room_view',
        'pool_room_view',
        'valley_room_view',
        'hill_room_view',
        'resort_room_view',
    ];

    /** name="lodging" — accommodation TYPE (Hotel, Apartment, Villa...). */
    public const LODGING_TYPES = [
        'AGRITOURISM', 'APARTMENT', 'APART_HOTEL', 'BED_AND_BREAKFAST', 'CABIN',
        'CAPSULE_HOTEL', 'CARAVAN_PARK', 'CASTLE', 'CHALET', 'CONDO', 'CONDO_RESORT',
        'COTTAGE', 'COUNTRY_HOUSE', 'CRUISE', 'GUEST_HOUSE', 'HOLIDAY_PARK', 'HOSTAL',
        'HOSTEL', 'HOTEL', 'HOTEL_RESORT', 'HOUSE_BOAT', 'INN', 'LODGE', 'MOTEL', 'PALACE',
        'PENSION', 'POUSADA_BRAZIL', 'POUSADA_PORTUGAL', 'RANCH', 'RESIDENCE', 'RIAD',
        'RYOKAN', 'SAFARI', 'TOWNHOUSE', 'TREE_HOUSE', 'VACATION_HOME', 'VILLA',
    ];

    /** name="mealPlan" — direct mapping candidate for our own meal_plan_preference slugs
     *  (sve_ukljuceno/dorucak/pun_pansion/dorucak_vecera) once real Hotels.com meal-plan price
     *  research starts — see BudgetEstimationEngine::MEAL_PLAN_COVERAGE_RATIOS and
     *  GeographyResolver::mealPlanFitFor()/TaxonomyNode::offersMealPlan(). */
    public const MEAL_PLAN = [
        'ALL_INCLUSIVE',
        'FREE_BREAKFAST',
        'FULL_BOARD',
        'HALF_BOARD',
    ];

    /** name="star" — property class, ×10 (10=1 star ... 50=5 stars). */
    public const STAR = [10, 20, 30, 40, 50];

    /** name="guestRating" — 35=Good 7+, 40=Very good 8+, 45=Wonderful 9+, 'ANY'=no filter. */
    public const GUEST_RATING = [35, 40, 45, 'ANY'];

    /** name="accessibility". */
    public const ACCESSIBILITY = [
        'ACCESSIBLE_BATHROOM',
        'ACCESSIBLE_PARKING',
        'ELEVATOR',
        'IN_ROOM_ACCESSIBLE',
        'ROLL_IN_SHOWER',
        'SERVICE_ANIMAL',
        'SIGN_LANGUAGE_INTERPRETER',
        'STAIR_FREE_PATH',
    ];

    /** name="bedroomFilter" — number of bedrooms, 0-4. */
    public const BEDROOM_COUNT = [0, 1, 2, 3, 4];

    /** name="paymentType". FREE_CANCELLATION is the real candidate for the "show the actual
     *  cancellation-price-delta" idea (see project_alternate_affiliate_cookie_options memory) —
     *  parked, not built yet. */
    public const PAYMENT_TYPE = [
        'FREE_CANCELLATION',
        'GIFT_CARD',
        'PAY_LATER',
        'pay_with_affirm', // US-market financing (Affirm), not relevant to a DACH audience
    ];

    /** name="rewards". */
    public const REWARDS = [
        'MEMBER_ONLY',
        'VIP',
        'discounted_property',
    ];

    /** name="availableFilter". */
    public const AVAILABLE_ONLY = 'SHOW_AVAILABLE_ONLY';
}
