import { Injectable, computed, signal } from '@angular/core';
import { GraphqlService } from './graphql.service';
import { SearchSession, TaxonomyNode, WizardAnswers, WizardCampaign, WizardQuestion, WizardStep } from './wizard.types';

const WIZARD_STEPS_QUERY = `
  query WizardSteps {
    wizardSteps {
      id key label
      questions {
        id key label inputType taxonomyType sessionField allowFreeText mandatory
        step { id key label }
        dependsOn { id }
        options { id slug label }
      }
    }
  }
`;

const START_SESSION_MUTATION = `
  mutation StartSession {
    startSearchSession { id status selectedTaxonomyNodeIds }
  }
`;

/** `campaignKey` is the SINGLE place this resolves from — a route path segment today, a
 *  subdomain later; nothing downstream (this service, WizardComponent) cares which. See
 *  wizard_architecture memory, 2026-07-30. */
const WIZARD_CAMPAIGN_QUERY = `
  query WizardCampaign($key: String!) {
    wizardCampaign(key: $key) {
      id key label landingHeadline meta
      questions {
        id key label inputType taxonomyType sessionField allowFreeText mandatory
        step { id key label }
        dependsOn { id }
        options { id slug label }
      }
    }
  }
`;

const START_CAMPAIGN_SESSION_MUTATION = `
  mutation StartCampaignSession($campaignKey: String!) {
    startCampaignSession(campaignKey: $campaignKey) { id status selectedTaxonomyNodeIds }
  }
`;

/** Bridges a homeCitySearch result (world_cities, not a taxonomy node) into a real
 *  taxonomy_node id — see WorldCityResolver::selectAsHomeCity. Called before setAnswer so
 *  home_city keeps storing a plain taxonomy-node id like every other taxonomy_choice field. */
const SELECT_WORLD_CITY_MUTATION = `
  mutation SelectWorldCityAsHomeCity($worldCityId: ID!) {
    selectWorldCityAsHomeCity(worldCityId: $worldCityId) { id }
  }
`;

/** Auto-detects home_city from the visitor's IP instead of asking — see WorldCityResolver::
 *  detectHomeCity and wizard_architecture memory, 2026-08-04. Returns null (never throws
 *  server-side) when it can't be geolocated; the frontend just leaves home_city unanswered
 *  in that case, same as if the (now-removed) question had simply never been shown. */
const DETECT_HOME_CITY_MUTATION = `
  mutation DetectHomeCity {
    detectHomeCity { id label }
  }
`;

const RECORD_WIZARD_EVENT_MUTATION = `
  mutation RecordWizardEvent($sessionId: ID!, $eventType: String!, $payload: JSON) {
    recordWizardEvent(sessionId: $sessionId, eventType: $eventType, payload: $payload)
  }
`;

/** See SearchSessionQueryCompiler (backend) — refreshed after every step so the debug panel
 *  (wizard.html) shows what's ACTUALLY mapped/inferred so far, including recommended-dates
 *  fallback before a real date is picked. Best-effort: a failure here must never block the
 *  wizard flow, this is a dev aid, not a required step. */
const COMPILED_QUERY = `
  query CompiledSearchQuery($sessionId: ID!) {
    compiledSearchQuery(sessionId: $sessionId)
  }
`;

/** First real AI feature in this project, 2026-08-10 — see HonestReportGenerator (backend).
 *  listingName/Description/reviews come from whatever the caller has (today, MOCK_HOTELS on
 *  the frontend; real Booking.com listing text later) — this mutation doesn't care which. */
const GENERATE_HONEST_REPORT_MUTATION = `
  mutation GenerateHonestReport($sessionId: ID!, $listingName: String!, $listingDescription: String!, $reviews: [String!]) {
    generateHonestReport(sessionId: $sessionId, listingName: $listingName, listingDescription: $listingDescription, reviews: $reviews) {
      pros cons summary
    }
  }
`;

const UPDATE_SESSION_MUTATION = `
  mutation UpdateSession($id: ID!, $input: UpdateSearchSessionInput!) {
    updateSearchSession(id: $id, input: $input) {
      id status tripType { id slug label } groupType { id slug label }
      persona { id slug label } countryRegion { id slug label } city { id slug label }
      budgetTier { id slug label } tipSmestaja { id slug label }
      adultsCount childrenAges needsCrib numberOfRooms terminCategory dateFrom dateTo freeTextAnswers
      selectedTaxonomyNodeIds
    }
  }
`;

const SUGGESTED_GEOGRAPHY_QUERY = `
  query SuggestedGeography($sessionId: ID!, $type: String!, $parentId: ID, $parentIds: [ID!]) {
    suggestedGeography(sessionId: $sessionId, type: $type, parentId: $parentId, parentIds: $parentIds) {
      id slug label matchScore meta implied matchedTags priceRank
      parent { label meta }
    }
  }
`;

/** Maps a WizardQuestion.sessionField to the corresponding key in UpdateSearchSessionInput. */
const SESSION_FIELD_TO_INPUT_KEY: Record<string, string> = {
  trip_type_id: 'tripTypeId',
  adults_count: 'adultsCount',
  children_ages: 'childrenAges',
  needs_crib: 'needsCrib',
  number_of_rooms: 'numberOfRooms',
  total_budget: 'totalBudget',
  group_type_id: 'groupTypeId',
  persona_id: 'personaId',
  budget_tier_id: 'budgetTierId',
  tip_smestaja_id: 'tipSmestajaId',
  termin_category: 'terminCategory',
  country_region_id: 'countryRegionId',
  city_id: 'cityId',
};

@Injectable({ providedIn: 'root' })
export class WizardService {
  readonly steps = signal<WizardStep[]>([]);
  readonly currentStepIndex = signal(0);

  /** Ordered list of step indices actually shown so far — the chat-scroll UI (wizard.html)
   *  renders every one of these, collapsed except the last. NOT the same as "0..currentIndex"
   *  since invisible steps (see isStepVisible) are skipped and must never appear as a blank
   *  collapsed row. See wizard_architecture memory, 2026-08-04 "chat-scroll" redesign. */
  readonly visitedStepIndices = signal<number[]>([]);

  readonly sessionId = signal<string | null>(null);
  readonly answers = signal<WizardAnswers>({});
  readonly loading = signal(false);

  /** Admin-editable per-campaign tunables (default budget etc.) — see WizardCampaign.meta.
   *  Null for the non-campaign generic flow, or before a campaign has loaded. */
  readonly campaignMeta = signal<Record<string, unknown> | null>(null);

  /** Mirrors backend SearchSession::selectedTaxonomyNodeIds() — the source of truth for
   *  evaluating WizardQuestion.dependsOn, kept in sync from every mutation response rather
   *  than re-derived from raw answers client-side (slug vs id shapes differ per field). */
  readonly selectedTaxonomyNodeIds = signal<Set<string>>(new Set());

  /** SearchSessionQueryCompiler's output (bookingParams + honestReportSignals) for the
   *  session so far — refreshed after every step, shown as a debug panel (see wizard.html).
   *  Owner's explicit ask, 2026-07-30: see mapped/inferred data live, not raw answers, to spot
   *  gaps faster. Null until the first successful fetch. */
  readonly compiledQuery = signal<Record<string, unknown> | null>(null);

  readonly currentStep = computed(() => this.steps()[this.currentStepIndex()] ?? null);

  /** Remembered from init() so refreshLabels() can re-run the SAME query on a locale switch —
   *  see refreshLabels() docblock. */
  private campaignKey: string | null = null;

  readonly totalTravelers = computed(() => {
    const adults = (this.answers()['adults_count'] as number) || 0;
    const children = (this.answers()['children_ages'] as number[]) || [];
    return adults + children.length;
  });

  readonly hasChildren = computed(() => ((this.answers()['children_ages'] as number[]) || []).length > 0);

  constructor(private gql: GraphqlService) {}

  /**
   * `campaignKey` (e.g. "kasno-letovanje") switches to the wizard_campaigns-backed flow — see
   * wizard_architecture memory, 2026-07-30. Loads that campaign's own question subset/order
   * (NOT the global wizardSteps) and starts the session via startCampaignSession, which applies
   * the campaign's preset_answers server-side (e.g. termin_category) before this method ever
   * returns — the implies/excludes engine sees them immediately, no separate step needed.
   *
   * Each campaign question becomes its own single-question synthetic "step" (`steps` stays
   * `WizardStep[]` either way) so the rest of this service and WizardComponent's rendering need
   * zero changes to support campaigns — they only ever see "the current step's questions."
   */
  async init(campaignKey?: string): Promise<void> {
    this.campaignKey = campaignKey ?? null;
    this.loading.set(true);
    try {
      if (campaignKey) {
        const [campaignData, sessionData] = await Promise.all([
          this.gql.request<{ wizardCampaign: WizardCampaign }>(WIZARD_CAMPAIGN_QUERY, { key: campaignKey }),
          this.gql.request<{ startCampaignSession: SearchSession }>(START_CAMPAIGN_SESSION_MUTATION, { campaignKey }),
        ]);
        this.steps.set(this.groupCampaignQuestionsIntoSteps(campaignData.wizardCampaign.questions));
        this.campaignMeta.set(campaignData.wizardCampaign.meta ?? null);
        this.sessionId.set(sessionData.startCampaignSession.id);
        this.selectedTaxonomyNodeIds.set(new Set(sessionData.startCampaignSession.selectedTaxonomyNodeIds ?? []));
        this.seedVisitedHistory();
        await this.refreshCompiledQuery();
        return;
      }

      const [stepsData, sessionData] = await Promise.all([
        this.gql.request<{ wizardSteps: WizardStep[] }>(WIZARD_STEPS_QUERY),
        this.gql.request<{ startSearchSession: SearchSession }>(START_SESSION_MUTATION),
      ]);
      this.steps.set(stepsData.wizardSteps);
      this.sessionId.set(sessionData.startSearchSession.id);
      this.selectedTaxonomyNodeIds.set(new Set(sessionData.startSearchSession.selectedTaxonomyNodeIds ?? []));
      this.seedVisitedHistory();
      await this.refreshCompiledQuery();
    } finally {
      this.loading.set(false);
    }
  }

  /**
   * Groups a campaign's flat, campaign-ordered question list back onto the SAME pages the
   * generic flow already uses (each question's own global `wizard_step`) — e.g. adults_count/
   * children_ages/needs_crib/number_of_rooms/group_type/total_budget all land on one page,
   * exactly like the generic '' route, instead of one page per question. A question with no
   * `step` (shouldn't happen in practice, defensive only) gets its own single-question page.
   * Relies on same-step questions already being contiguous in campaign sort_order (true for
   * every campaign seeded so far — see WizardSeeder::seedWizardCampaigns) rather than
   * re-sorting, so a campaign COULD interleave two different steps' questions on purpose later
   * if that's ever a real need.
   */
  private groupCampaignQuestionsIntoSteps(questions: WizardQuestion[]): WizardStep[] {
    const steps: WizardStep[] = [];

    for (const question of questions) {
      const step = question.step;
      const last = steps[steps.length - 1];

      if (step && last?.key === step.key) {
        last.questions.push(question);
      } else {
        steps.push({
          id: step?.id ?? question.id,
          key: step?.key ?? question.key,
          label: step?.label ?? question.label,
          questions: [question],
        });
      }
    }

    return steps;
  }

  /**
   * Re-fetches step/question/option labels in the CURRENT locale (see GraphqlService's
   * X-Locale header) without touching session state — same steps/questions in the same order,
   * just relabeled. Triggered by WizardComponent when the user switches EN/DE mid-flow (owner's
   * ask, 2026-08-11: "ne menja se sve na promenu jezika, a mora") — backend-sourced labels used
   * to stay frozen at whatever locale was active during the original fetch, since switching the
   * toggle only re-renders the STATIC i18n strings, never re-fetches GraphQL data on its own.
   */
  async refreshLabels(): Promise<void> {
    if (this.campaignKey) {
      const data = await this.gql.request<{ wizardCampaign: WizardCampaign }>(WIZARD_CAMPAIGN_QUERY, {
        key: this.campaignKey,
      });
      this.steps.set(this.groupCampaignQuestionsIntoSteps(data.wizardCampaign.questions));
      return;
    }

    const data = await this.gql.request<{ wizardSteps: WizardStep[] }>(WIZARD_STEPS_QUERY);
    this.steps.set(data.wizardSteps);
  }

  setAnswer(key: string, value: unknown): void {
    this.answers.update((a) => ({ ...a, [key]: value }));
  }

  /** Resolves a homeCitySearch pick to a real taxonomy_node id, then stores it exactly like any
   *  other taxonomy_choice answer — see SELECT_WORLD_CITY_MUTATION. Dead as of 2026-08-04 (the
   *  home_city question/step no longer renders, see isQuestionVisible below) but left in place
   *  rather than ripped out — CitySearchComponent still works standalone if a manual fallback
   *  is ever wanted again. */
  async selectHomeCityFromWorldCity(worldCityId: string): Promise<void> {
    const data = await this.gql.request<{ selectWorldCityAsHomeCity: { id: string } }>(
      SELECT_WORLD_CITY_MUTATION,
      { worldCityId }
    );
    this.setAnswer('home_city', data.selectWorldCityAsHomeCity.id);
  }

  /**
   * Silently detects and persists home_city from the visitor's IP — owner's explicit call,
   * 2026-08-04, replacing the manual city-search question entirely. No UI whatsoever; only
   * surfaces indirectly once a destination is chosen, via the existing `distance_km` signal in
   * toHonestReportSignals()/compiledQuery. Fire-and-forget by design (see call site in
   * WizardComponent): the geo-IP lookup can be slow/unreliable and must never delay or block
   * the visible wizard flow. Writes DIRECTLY via updateSearchSession (bypassing
   * persistCurrentStep's step-based field mapping, which only ever runs for the questions on
   * whatever step is currently active) since there's no step boundary tied to this anymore —
   * it's a background action fired once at session start, not a step answer.
   */
  async detectHomeCity(): Promise<void> {
    const sessionId = this.sessionId();
    if (!sessionId) return;

    try {
      const data = await this.gql.request<{ detectHomeCity: { id: string; label: string } | null }>(
        DETECT_HOME_CITY_MUTATION
      );
      const node = data.detectHomeCity;
      if (!node) return;

      this.setAnswer('home_city', node.id);
      const result = await this.gql.request<{ updateSearchSession: SearchSession }>(UPDATE_SESSION_MUTATION, {
        id: sessionId,
        input: { homeCityId: node.id },
      });
      this.selectedTaxonomyNodeIds.set(new Set(result.updateSearchSession.selectedTaxonomyNodeIds ?? []));
      await this.refreshCompiledQuery();
    } catch {
      // best-effort — a visitor we can't geolocate just has no home_city, same as before
    }
  }

  /** Raw funnel log — owner's ask, 2026-08-13. Fire-and-forget, same "must never block or
   *  error the wizard" rule as detectHomeCity: a dropped log line is not worth interrupting a
   *  real visitor over. */
  async recordEvent(eventType: string, payload?: Record<string, unknown>): Promise<void> {
    const sessionId = this.sessionId();
    if (!sessionId) return;

    try {
      await this.gql.request(RECORD_WIZARD_EVENT_MUTATION, { sessionId, eventType, payload: payload ?? null });
    } catch {
      // best-effort — see docblock
    }
  }

  /**
   * Owner's ask, 2026-08-12: on the results screen, let the traveler quickly re-run the search
   * against a DIFFERENT one of their shortlisted cities without walking back through the whole
   * wizard. Same session (not a fork) — direct write via updateSearchSession, same pattern as
   * detectHomeCity above, bypassing persistCurrentStep since there's no active step here.
   */
  async switchResultsCity(cityId: string): Promise<void> {
    const sessionId = this.sessionId();
    if (!sessionId) return;

    this.setAnswer('city', cityId);
    const result = await this.gql.request<{ updateSearchSession: SearchSession }>(UPDATE_SESSION_MUTATION, {
      id: sessionId,
      input: { cityId },
    });
    this.selectedTaxonomyNodeIds.set(new Set(result.updateSearchSession.selectedTaxonomyNodeIds ?? []));
    await this.refreshCompiledQuery();
  }

  /** Null if there's no active session yet (shouldn't happen once finished() is true, but
   *  matches this class's existing "best-effort, never throw into the caller" convention for
   *  anything that isn't core wizard flow — see detectHomeCity). */
  async generateHonestReport(
    listingName: string,
    listingDescription: string,
    reviews: string[]
  ): Promise<{ pros: string[]; cons: string[]; summary: string } | null> {
    const sessionId = this.sessionId();
    if (!sessionId) return null;

    try {
      const data = await this.gql.request<{
        generateHonestReport: { pros: string[]; cons: string[]; summary: string };
      }>(GENERATE_HONEST_REPORT_MUTATION, { sessionId, listingName, listingDescription, reviews });
      return data.generateHonestReport;
    } catch {
      return null;
    }
  }

  getAnswer(key: string): unknown {
    return this.answers()[key];
  }

  /** Whether a question within the current step should be shown, given branching rules. */
  isQuestionVisible(question: WizardQuestion): boolean {
    // Themed entry points (init(theme)) pre-set termin_category before the user ever reaches
    // this step — don't ask again what a "Nađi mi još malo sunca"-style landing already
    // answered. Specific to this one field (not a generic "hide anything pre-filled" rule) —
    // `suggests`-filled fields must stay visible/editable, only this theme pre-set should hide.
    if (question.key === 'termin_category') {
      return !this.answers()['termin_category'];
    }
    // Auto-detected from the visitor's IP now (see detectHomeCity) instead of asked — owner's
    // explicit call, 2026-08-04. Always hidden; this makes its whole step (a single-question
    // step) auto-skip via the generic isStepVisible rule below, no separate step-removal
    // needed. See wizard.service.ts's selectHomeCityFromWorldCity/CitySearchComponent
    // docblocks — left in place, just unreachable, in case a manual fallback is wanted later.
    if (question.key === 'home_city') {
      return false;
    }
    // Group-size taxonomy (owner's call, 2026-07-30): group_type (social structure — family/
    // school/club/...) now applies to EITHER any family (has children, regardless of count) OR
    // any adult-only group of 3+ — previously excluded families entirely, which was wrong
    // (a family should get porodica/skola just as much as a 4-friend trip gets klub/društvo).
    if (question.key === 'group_type') {
      return this.hasChildren() || this.totalTravelers() >= 3;
    }
    // persona/persona_group/relationship_type are mutually exclusive by group size — see
    // seedWizardSteps' persona step comment. Universal categories either way, just singular
    // single-choice for solo vs. plural multi-choice for everyone else (2+, whether couple,
    // friend group, or family — "what does this crew care about" reads fine for all of them).
    if (question.key === 'persona') {
      return this.totalTravelers() === 1;
    }
    if (question.key === 'persona_group') {
      return this.totalTravelers() >= 2;
    }
    if (question.key === 'relationship_type') {
      return !this.hasChildren() && (this.answers()['adults_count'] as number) === 2;
    }
    // Owner's call, 2026-08-13 ("vecina korisnika su idioti") — self-catering is its own
    // separate, mandatory meal_style question now; someone who already said "I'll cook myself"
    // has no use for "want meals included?" (the hotel meal-plan tiers).
    if (question.key === 'meal_plan_preference') {
      return this.answers()['meal_style'] !== 'kuva_sam';
    }
    // needs_crib no longer has a visibility gate here — it's now a per-child array fully
    // owned by <app-travelers-input> (each row shows its own crib toggle only for that child's
    // age ≤2). This used to gate the OLD single blanket checkbox, but since needs_crib is
    // filtered out of visibleQuestions unconditionally (see TRAVELERS_QUESTION_KEYS in
    // wizard.ts) this check only ever affected persistCurrentStep()'s "skip invisible
    // questions" filter — meaning the whole needs_crib array silently stopped being sent to
    // the backend the moment no child was ≤2 anymore, discarding real answers. Bug caught
    // 2026-08-03 ("creeb se gubi, di na onchange").
    if (question.dependsOn) {
      return this.selectedTaxonomyNodeIds().has(question.dependsOn.id);
    }
    return true;
  }

  /**
   * Whether an entire step should be shown — generically skipped when NONE of its questions
   * are currently visible (e.g. a lone `group_type` page for a group where it doesn't apply),
   * including the campaign-mode synthetic one-question-per-step pages (see init()). No more
   * persona-specific special-case — persona/persona_group between them cover every group size
   * (see isQuestionVisible), so the "persona" step now always has exactly one visible question,
   * same generic rule as everything else.
   */
  isStepVisible(step: WizardStep): boolean {
    return step.questions.some((q) => this.isQuestionVisible(q));
  }

  /** parentIds (plural) narrows to ANY of several selected countries — owner's ask, 2026-08-12,
   *  Country/region became multi-select. Takes priority over the singular parentId when both
   *  are passed (callers should really only ever pass one or the other). */
  async loadGeographyOptions(type: string, parentId?: string | null, parentIds?: string[] | null): Promise<TaxonomyNode[]> {
    const sessionId = this.sessionId();
    if (!sessionId) return [];
    const data = await this.gql.request<{ suggestedGeography: TaxonomyNode[] }>(SUGGESTED_GEOGRAPHY_QUERY, {
      sessionId,
      type,
      parentId: parentIds?.length ? null : parentId ?? null,
      parentIds: parentIds?.length ? parentIds : null,
    });
    return data.suggestedGeography;
  }

  /** Persist the current step's collected answers to the session, then advance. */
  async goNext(): Promise<void> {
    await this.persistCurrentStep();

    let nextIndex = this.currentStepIndex() + 1;
    const steps = this.steps();
    while (nextIndex < steps.length && !this.isStepVisible(steps[nextIndex])) {
      nextIndex++;
    }
    this.currentStepIndex.set(nextIndex);
    if (nextIndex < steps.length) {
      this.visitedStepIndices.update((v) => [...v, nextIndex]);
    }
  }

  /** Re-opens an already-visited step for editing — truncates history PAST it (not just to
   *  it), so subsequent goNext() calls naturally re-derive whatever comes after from scratch.
   *  This is correct, not lossy: if the edit changes branching (e.g. adds a child, which
   *  changes group_type visibility), the old forward path may no longer be the right one
   *  anyway. Answers already persisted server-side for the truncated steps aren't discarded —
   *  only the local chat-scroll display history shrinks. */
  editStep(index: number): void {
    const history = this.visitedStepIndices();
    const pos = history.indexOf(index);
    if (pos === -1) return;

    this.visitedStepIndices.set(history.slice(0, pos + 1));
    this.currentStepIndex.set(index);
  }

  goBack(): void {
    const history = this.visitedStepIndices();
    if (history.length <= 1) return;
    this.editStep(history[history.length - 2]);
  }

  private seedVisitedHistory(): void {
    const steps = this.steps();
    let start = 0;
    while (start < steps.length && !this.isStepVisible(steps[start])) {
      start++;
    }
    this.currentStepIndex.set(start);
    this.visitedStepIndices.set(start < steps.length ? [start] : []);
  }

  private async persistCurrentStep(): Promise<void> {
    const step = this.currentStep();
    const sessionId = this.sessionId();
    if (!step || !sessionId) return;

    const input: Record<string, unknown> = {};
    const freeTextAnswers: Record<string, unknown> = {};

    for (const question of step.questions) {
      if (!this.isQuestionVisible(question)) continue;
      if (!(question.key in this.answers())) continue;

      const value = this.answers()[question.key];
      const field = question.sessionField;
      if (!field) continue;

      if (field.startsWith('free_text_answers.')) {
        const subKey = field.split('.')[1];
        freeTextAnswers[subKey] = value;
      } else if (field === 'date_from,date_to') {
        const [from, to] = value as [string, string];
        input['dateFrom'] = from;
        input['dateTo'] = to;
      } else {
        const inputKey = SESSION_FIELD_TO_INPUT_KEY[field];
        if (inputKey) input[inputKey] = value;
      }
    }

    if (Object.keys(freeTextAnswers).length > 0) {
      input['freeTextAnswers'] = freeTextAnswers;
    }

    if (Object.keys(input).length === 0) {
      await this.refreshCompiledQuery();
      return;
    }

    const result = await this.gql.request<{ updateSearchSession: SearchSession }>(UPDATE_SESSION_MUTATION, {
      id: sessionId,
      input,
    });

    this.selectedTaxonomyNodeIds.set(new Set(result.updateSearchSession.selectedTaxonomyNodeIds ?? []));
    this.syncAnswersFromSession(result.updateSearchSession);
    await this.refreshCompiledQuery();
  }

  /** Best-effort — a failure here (e.g. session not far enough along) must never block the
   *  wizard flow, this is a debug aid, not a required step. See compiledQuery. */
  private async refreshCompiledQuery(): Promise<void> {
    const sessionId = this.sessionId();
    if (!sessionId) return;

    try {
      const data = await this.gql.request<{ compiledSearchQuery: Record<string, unknown> }>(COMPILED_QUERY, {
        sessionId,
      });
      this.compiledQuery.set(data.compiledSearchQuery);
    } catch {
      // swallow — see docblock
    }
  }

  /**
   * A `suggests` relation (e.g. "jeftino" -> budget_tier "do_20e") writes straight to the
   * session server-side, but the answer only shows up pre-filled-and-editable in the UI once
   * the frontend's local `answers` signal knows about it too — otherwise the user reaches the
   * budget step and sees nothing selected despite the backend already having a value. This
   * pulls any session field the user hasn't answered locally yet back into `answers`, for
   * every question across every step (not just the current one), since an implication made on
   * an earlier step can affect a question several steps later.
   */
  private syncAnswersFromSession(session: SearchSession): void {
    const updates: WizardAnswers = {};

    for (const step of this.steps()) {
      for (const question of step.questions) {
        if (question.key in this.answers()) continue;

        const field = question.sessionField;
        if (!field || field.startsWith('free_text_answers.')) continue;

        if (field === 'date_from,date_to') {
          if (session.dateFrom || session.dateTo) {
            updates[question.key] = [session.dateFrom ?? '', session.dateTo ?? ''];
          }
          continue;
        }

        const sessionKey = SESSION_FIELD_TO_INPUT_KEY[field];
        const relationKey = sessionKey?.replace(/Id$/, '') as keyof SearchSession | undefined;
        const relationValue = relationKey ? (session[relationKey] as TaxonomyNode | undefined) : undefined;

        if (relationValue?.id) {
          updates[question.key] = relationValue.id;
        } else if (field === 'termin_category' && session.terminCategory) {
          updates[question.key] = session.terminCategory;
        }
      }
    }

    // Owner's catch, 2026-08-13: a `suggests` relation onto a preference_tag (e.g. group_type
    // "Family" -> "Family-friendly atmosphere") writes into implied_preference_tags server-side
    // (counts toward match_score correctly) but was never shown as checked in the Vibe step —
    // only a HARD `implies` (locked, disabled checkbox) was ever visible. Backfilling it into the
    // real local `preference_tags` answer makes it show pre-checked AND freely editable — the
    // "contrarian retiree who wants to rave anyway" case the owner explicitly wanted to allow.
    // Skipped once the user has touched the field locally, same first-touch-wins rule as every
    // other field above.
    const impliedTags = (session.freeTextAnswers?.['implied_preference_tags'] as string[] | undefined) ?? [];
    if (impliedTags.length > 0 && !('preference_tags' in this.answers())) {
      const current = (this.answers()['preference_tags'] as string[] | undefined) ?? [];
      updates['preference_tags'] = Array.from(new Set([...current, ...impliedTags]));
    }

    if (Object.keys(updates).length > 0) {
      this.answers.update((a) => ({ ...a, ...updates }));
    }
  }
}
