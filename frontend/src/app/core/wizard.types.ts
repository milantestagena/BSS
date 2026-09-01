export interface TaxonomyNode {
  id: string;
  slug: string;
  label: string;
  meta?: Record<string, unknown> | null;
  matchScore?: number | null;
  /** Populated only when returned from suggestedGeography — true if forced on by an `implies`
   *  relation from an already-selected answer. See ChoiceComponent's `disabled` state. */
  implied?: boolean | null;
  /** Populated only when returned from suggestedGeography for type=country/city — preference_tag
   *  slugs this node matched. Used to group destination cards by shared match reason instead of
   *  a flat ranked list — see WizardComponent.groupedDestinations(). */
  matchedTags?: string[] | null;
  /** Populated only when returned from suggestedGeography for type=country/city, when
   *  total_budget AND real accommodation+food totals both exist: (accommodation + food) /
   *  total_budget * 100, rounded to 1 decimal. Absolute, not relative to the other candidates
   *  shown — replaces the old priceRank (2026-09-01), which never compared against the
   *  traveler's stated budget at all. Null when the inputs aren't known yet — see
   *  WizardComponent.budgetFitClass(). */
  budgetFitPercent?: number | null;
  /** Populated only when returned from suggestedGeography for type=country/city, when budget
   *  data exists: 'eating_out' | 'self_catering' | 'meal_plan' | null — which spending style
   *  this destination's price actually fit under. See WizardComponent.budgetNoteFor(). */
  budgetFit?: string | null;
  /** Populated only when returned from suggestedGeography for type=country/city: true if this
   *  destination only appears because nothing fit the stated budget and it's one of the 2
   *  closest fallbacks. See WizardComponent.budgetNoteFor(). */
  budgetCaveat?: boolean | null;
  /** Populated only when returned from suggestedGeography for type=country/city, when budget
   *  data exists — true if an all-inclusive meal plan would ALSO fit for the session's budget,
   *  regardless of the session's actual meal_style. Purely informational (owner's ask,
   *  2026-08-14: "mozemo mu kazemo negde imas all inclusive za te pare") — never affects
   *  budgetFit/inclusion/sorting. See WizardComponent.budgetNoteFor(). */
  allInclusiveFits?: boolean | null;
  /** Populated only when returned from suggestedGeography for type=country/city — true if this
   *  destination matched EVERY selected vibe preference_tag (not just some). For type=country,
   *  the backend also requires at least one child city to independently match all of them too,
   *  so a country never shows this without a real bookable city to back it up — see
   *  GeographyResolver::isPerfectMatch. See WizardComponent.isPerfectMatch(). */
  perfectMatch?: boolean | null;
  /** Populated only when returned from suggestedGeography for type=country/city, campaign mode
   *  only — true if a DestinationGuide row exists for this node + the session's campaign.
   *  Gates whether the optional "see full guide" link even shows — see
   *  DestinationGuideModalComponent. */
  hasGuide?: boolean | null;
  /** Populated only when returned from suggestedGeography — slugs this node EXCLUDES (a real
   *  contradiction, not just a preference nuance — e.g. preference_tag 'jeftino' excludes
   *  'kvalitet', opposite Booking sort directions). Live-deselected from the same multi-choice
   *  question when this node is picked — see QuestionInputComponent.onMultiChoiceToggle. */
  excludesSlugs?: string[] | null;
  /** Populated only when returned from suggestedGeography for type=country/city, when
   *  meal_plan_preference is answered — true if this destination doesn't really offer any of the
   *  requested meal plans (real, researched availability — see GeographyResolver::
   *  mealPlanFitFor). Never excludes the destination, only downranks it — see
   *  WizardComponent.budgetNoteFor(). */
  mealPlanCaveat?: boolean | null;
  /** Populated only when returned from suggestedGeography for type=country/city, when real
   *  Open-Meteo climate data exists for at least one of the months the session's trip dates
   *  span — real imported data, not estimated (see GeographyResolver::climateSummaryFor). min
   *  === max for a single-month trip, a real range for a trip spanning two calendar months.
   *  Null if no data exists for any spanned month. */
  climateAirTempC?: { min: number; max: number } | null;
  /** Same as climateAirTempC, sea surface temperature instead of air. */
  climateSeaTempC?: { min: number; max: number } | null;
  /** Populated only when returned from suggestedGeography for type=city — the parent country's
   *  label (+ meta, for the iso_code badge — see wizard.ts's countryCodeFor), so a mixed-country
   *  city grid (multi-select Country/region, 2026-08-13) can show which country each card
   *  belongs to. */
  parent?: { label: string; meta?: Record<string, unknown> | null } | null;
}

/** Optional "deep-dive" destination content — see DestinationGuide (backend) and
 *  DestinationGuideModalComponent (frontend). Fetched on demand, only when the modal opens,
 *  not preloaded per card. */
export interface DestinationGuide {
  id: string;
  itinerary?: { location: string; nights: number; highlight?: string | null }[] | null;
  accommodationCostNotes?: string | null;
  extraTips?: string[] | null;
  images?: { url: string; attribution?: string | null }[] | null;
  accommodationPriceEur?: number | null;
  accommodationPriceRangeEur?: { min: number; max: number } | null;
  /** Both shown regardless of the session's own meal_style pick — owner's call, 2026-08-19,
   *  a browsing visitor hasn't committed to a style yet. */
  foodCostEatingOutPerAdultPerDayEur?: number | null;
  foodCostSelfCateringPerAdultPerDayEur?: number | null;
}

export type WizardInputType =
  | 'taxonomy_choice'
  | 'taxonomy_multi_choice'
  | 'number'
  | 'number_array'
  | 'date_range'
  | 'boolean'
  | 'text';

export interface WizardQuestion {
  id: string;
  key: string;
  label: string;
  inputType: WizardInputType;
  taxonomyType: string | null;
  sessionField: string | null;
  allowFreeText: boolean;
  /** Blocks Proceed on this question's step until it's answered — see WizardComponent.canProceed(). */
  mandatory: boolean;
  /** Only show this question if this node is in the session's selectedTaxonomyNodeIds. Null = always show. */
  dependsOn: TaxonomyNode | null;
  options: TaxonomyNode[] | null;
  /** Only requested/populated for campaign mode — see WizardService.init(). */
  step?: { id: string; key: string; label: string } | null;
}

export interface WizardStep {
  id: string;
  key: string;
  label: string;
  questions: WizardQuestion[];
}

/** A themed entry point (e.g. "Kasno letovanje") — own question subset/order over the same
 *  global questions, plus preset answers applied server-side at session start. See
 *  wizard_architecture memory, 2026-07-30. */
export interface WizardCampaign {
  id: string;
  key: string;
  label: string;
  landingHeadline?: string | null;
  /** Admin-editable per-campaign tunables — see WizardSeeder's kasno-letovanje entry,
   *  deliberately not hardcoded in frontend logic. */
  meta?: Record<string, unknown> | null;
  /** WizardCampaign::presetTripLengthDays() — trip length implied by this campaign's preset
   *  termin_category. Null unless the campaign presets termin_category. */
  presetTripLengthDays?: number | null;
  questions: WizardQuestion[];
}

export interface SearchSession {
  id: string;
  status: string;
  tripType?: TaxonomyNode | null;
  adultsCount?: number | null;
  childrenAges?: number[] | null;
  needsCrib?: boolean[] | null;
  numberOfRooms?: number | null;
  groupType?: TaxonomyNode | null;
  persona?: TaxonomyNode | null;
  budgetTier?: TaxonomyNode | null;
  tipSmestaja?: TaxonomyNode | null;
  terminCategory?: string | null;
  dateFrom?: string | null;
  dateTo?: string | null;
  countryRegion?: TaxonomyNode | null;
  city?: TaxonomyNode | null;
  freeTextAnswers?: Record<string, unknown> | null;
  /** Mirrors backend SearchSession::selectedTaxonomyNodeIds() — used to evaluate WizardQuestion.dependsOn. */
  selectedTaxonomyNodeIds?: string[];
}

/** Answers collected in the browser, keyed by question key, before being written to the session. */
export type WizardAnswers = Record<string, unknown>;
