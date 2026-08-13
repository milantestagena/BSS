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
  /** Populated only when returned from suggestedGeography for type=country/city, when there's
   *  enough price data to rank: 1 (cheapest of the currently-shown candidates) to 5 (priciest).
   *  Null when there isn't enough data yet — see WizardComponent.priceRankClass(). */
  priceRank?: number | null;
  /** Populated only when returned from suggestedGeography for type=city — the parent country's
   *  label, so a mixed-country city grid (multi-select Country/region, 2026-08-13) can show
   *  which country each card belongs to. */
  parent?: { label: string } | null;
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
