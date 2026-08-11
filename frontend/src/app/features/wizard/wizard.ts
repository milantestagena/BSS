import { Component, ElementRef, OnInit, signal, ViewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute } from '@angular/router';
import { WizardService } from '../../core/wizard.service';
import { AuthService } from '../../core/auth.service';
import { TaxonomyNode, WizardQuestion, WizardStep } from '../../core/wizard.types';
import { QuestionInputComponent } from './question-input';
import { TravelersInputComponent, TravelersValue } from './travelers-input';
import { CitySearchComponent, WorldCityResult } from './city-search';
import { AmenityPickerComponent } from './amenity-picker';
import { ButtonComponent } from '../../ui/button';
import { SpinnerComponent } from '../../ui/spinner';

/** Questions rendered by the combined <app-travelers-input> widget instead of individually —
 *  see travelers-input.ts. */
const TRAVELERS_QUESTION_KEYS = new Set(['adults_count', 'children_ages', 'needs_crib']);

/** home_city renders via <app-city-search> (GeoNames typeahead) instead of the generic
 *  taxonomy_choice pill list — see city-search.ts. Owner's explicit ask, 2026-08-03. */
const HOME_CITY_QUESTION_KEY = 'home_city';

/** number_of_rooms is never shown as a raw number field (see showRoomsTogetherQuestion /
 *  onRoomsTogetherChoice) — groups of ≤3 auto-default to 1 room silently, larger groups get a
 *  "stay together?" yes/no instead of typing a room count. Owner's explicit call, 2026-07-30. */
const ROOMS_QUESTION_KEY = 'number_of_rooms';

/** amenities_yes/amenities_no render via <app-amenity-picker> (combined typeahead over
 *  tip_smestaja/accommodation_facility/room_facility/meal_plan) instead of two separate
 *  generic pill grids — see amenity-picker.ts. Owner's design, 2026-08-04. */
const AMENITY_YES_KEY = 'amenities_yes';
const AMENITY_NO_KEY = 'amenities_no';

/** No UI of its own — see onAmenityUnmatchedText. Exists purely so its session_field flows
 *  through persistCurrentStep like every other free_text_answers field. */
const SMESTAJ_AVOID_KEY = 'smestaj_avoid';

/** "Screen 2" — country_region/city render as bigger cards instead of plain pills, with a
 *  hover-revealed vibe_profile description in the reserved left column. See
 *  wizard_architecture "FINAL WORKFLOW DESIGN", 2026-08-04. */
const DESTINATION_CARD_KEYS = new Set(['country_region', 'city']);

/** Shown while transitioning from "screen 1" (all Q&A done) into "screen 2" (destination
 *  cards) — owner's own framing: "zarolamo neki loader koji kao nesto mnogo racuna :D".
 *  Real geography loading runs in parallel underneath (see runScreenTransition), this is
 *  purely a perceived-value device, not fake-for-the-sake-of-fake. */
const CALCULATING_MESSAGES = [
  'Checking the weather forecast...',
  'Comparing accommodation prices...',
  'Finding beaches that suit you...',
  'Putting together your suggestions...',
];
const CALCULATING_MIN_DURATION_MS = 1800;

/** Idiot-proof, plain-English "why are we asking this" blurb per wizard step, shown in the
 *  reserved left column (see stepDescription()) — owner's ask, 2026-08-06, aimed at a reviewer
 *  (e.g. the Booking Affiliate application) who's never seen this flow before and needs to
 *  follow along without guessing. Keyed by WizardStep.key, same keys used by both the generic
 *  flow and every campaign (campaigns only ever reorder/select from this same fixed step set,
 *  see WizardSeeder::seedWizardSteps()). */
const STEP_DESCRIPTIONS: Record<string, string> = {
  trip_type: 'What kind of trip is this? This one choice shapes every question that follows.',
  broj_putnika: "Just headcount and a rough budget for now — how many of you, any kids, and what you're comfortable spending. We'll match destinations to this later.",
  odakle_putujes: 'Your home city, so we can give you a realistic sense of how far each suggestion actually is.',
  termin: "When you're planning to travel. We already suggest a window based on the campaign, but you can fine-tune the exact dates.",
  persona: "A quick read on what kind of traveler(s) you are — this steers which destinations and vibes we suggest next.",
  preferencije: "What matters most about the trip's atmosphere, plus your nightly budget — helps us narrow things down to a shortlist that actually fits.",
  zemlja_regija: "Based on everything so far, here are the countries/regions that fit best. Pick one, or tell us if none of them feel right.",
  grad: 'Now narrowing down to a specific city or resort town within that region.',
  smestaj: "Last step — the specific things that would make (or break) your stay: amenities, must-haves, deal-breakers.",
};

/** Shown ABOVE the first step's description only, 2026-08-06 (owner's ask) — orients a
 *  first-time viewer to the fact that this whole flow is scoped to ONE campaign at a time
 *  before they've seen enough of it to infer that themselves. */
const CAMPAIGN_INTRO_BLURB =
  "This flow is built around one campaign at a time. Right now you're looking at \"Late Summer\" — squeezing in warm-weather travel before the season ends. More campaigns are planned down the line (city breaks, holiday trips, full summer/winter vacations), each with its own tailored flow like this one.";

interface ThemeIntro {
  title: string;
  subtitle: string;
  cta: string;
}

/**
 * Mock results screen — see wizard_architecture memory, 2026-08-06. Owner's explicit ask:
 * NOT real Booking data (that integration doesn't exist yet), purely a presentation/demo
 * artifact showing what the payoff moment will eventually look like — doubles as screenshot
 * material for the Booking Affiliate application. Clearly labeled as such in the template,
 * not just in code comments. Names are deliberately generic/invented, never real property
 * names, to avoid any impression of an actual Booking relationship.
 */
interface MockHotel {
  name: string;
  pricePerNightEur: number;
  rating: number; // out of 10
  sqm: number;
  distanceToBeachM: number;
  propertyType: string;
  /** Fed to generateHonestReport as-is — real listing text once Booking data exists, invented
   *  but realistic for now (2026-08-10). Deliberately includes at least one minor gripe per
   *  review, same "honest, not a sales pitch" spirit as the AI prompt itself. */
  description: string;
  reviews: string[];
}

const MOCK_HOTELS: MockHotel[] = [
  {
    name: 'Seaside Breeze Apartments', pricePerNightEur: 62, rating: 8.4, sqm: 55, distanceToBeachM: 150, propertyType: 'Apartment',
    description: 'A bright one-bedroom apartment on a quiet side street, 150m from the beach, with a private balcony, AC, and a shared rooftop terrace.',
    reviews: [
      'Balcony had a great sea glimpse, and the rooftop terrace was perfect for evening drinks.',
      'Kitchen was small but had everything we needed. Building has no elevator, worth knowing if you have luggage.',
    ],
  },
  {
    name: 'Villa Aurora', pricePerNightEur: 78, rating: 9.1, sqm: 90, distanceToBeachM: 400, propertyType: 'Villa',
    description: 'A spacious 3-bedroom villa with a private pool and garden, 400m from the beach, popular with families and larger groups.',
    reviews: [
      'The private pool made this trip — kids barely wanted to leave the villa. Plenty of space for two families.',
      'Beautiful and quiet, but you need a car or a 5-10 min walk to reach any restaurants.',
    ],
  },
  {
    name: 'Coral Bay Retreat', pricePerNightEur: 54, rating: 7.8, sqm: 40, distanceToBeachM: 80, propertyType: 'Studio',
    description: 'A cozy studio 80m from the beach, with a small shared pool and a kitchenette, on a quiet residential street 10 minutes from the main strip.',
    reviews: [
      'Loved how close it was to the beach, but the walls are thin and we heard neighbors at night.',
      'Kitchenette was basic but enough for breakfast. AC worked great in the heat.',
    ],
  },
  {
    name: 'Sunset Harbor Suites', pricePerNightEur: 69, rating: 8.7, sqm: 65, distanceToBeachM: 250, propertyType: 'Suite',
    description: 'A modern suite overlooking the harbor, 250m from the beach, with a full kitchen, washing machine, and free parking.',
    reviews: [
      'Harbor view at sunset was stunning, and having a washing machine was a nice bonus for a longer stay.',
      'Free parking was tight to maneuver into, but manageable. Great value for the space.',
    ],
  },
  {
    name: 'Olive Grove Studios', pricePerNightEur: 48, rating: 7.5, sqm: 35, distanceToBeachM: 600, propertyType: 'Studio',
    description: 'A simple, budget-friendly studio set among olive trees, 600m from the beach, with a fan (no AC) and a small shared pool.',
    reviews: [
      'Great price for what you get, and the pool area was peaceful. Beach walk is a bit far in the midday heat.',
      'No AC was tough on the hottest nights — a fan alone wasn\'t quite enough for us.',
    ],
  },
  {
    name: 'Blue Horizon Residence', pricePerNightEur: 71, rating: 8.9, sqm: 70, distanceToBeachM: 120, propertyType: 'Apartment',
    description: 'A two-bedroom apartment 120m from the beach with sea-view balcony, AC in both bedrooms, and a communal pool with sun loungers.',
    reviews: [
      'Sea view from the balcony every morning was unbeatable, and the pool loungers were rarely all taken.',
      'Very close to a beach bar, so it can get a bit noisy on weekend evenings.',
    ],
  },
  {
    name: 'Palm Court Apartments', pricePerNightEur: 59, rating: 8.0, sqm: 50, distanceToBeachM: 300, propertyType: 'Apartment',
    description: 'A family-run apartment complex 300m from the beach, with a playground on-site, AC, and a communal BBQ area.',
    reviews: [
      'Playground was great for our kids, and the owners were incredibly helpful with local tips.',
      'Decor is a bit dated, but everything was clean and worked fine.',
    ],
  },
  {
    name: 'Marina View Loft', pricePerNightEur: 65, rating: 8.3, sqm: 45, distanceToBeachM: 90, propertyType: 'Loft',
    description: 'A stylish open-plan loft 90m from the beach, overlooking the marina, with AC, a rainfall shower, and a small work desk.',
    reviews: [
      'Beautiful marina view and the shower was genuinely great. Loved working from the desk with that view.',
      'Open-plan layout means zero privacy between sleeping and living area — fine for a couple, not for friends sharing.',
    ],
  },
  {
    name: 'Golden Sands Bungalow', pricePerNightEur: 56, rating: 8.6, sqm: 48, distanceToBeachM: 60, propertyType: 'Bungalow',
    description: 'A single-story bungalow just 60m from the beach, with a private shaded patio, AC, and free bike rentals for guests.',
    reviews: [
      'Closest we\'ve stayed to a beach — genuinely a 60-second walk. Bikes were a nice touch for exploring.',
      'Very basic furnishings, but honestly you\'re barely indoors given how close the beach is.',
    ],
  },
  {
    name: 'Whitewashed Villa', pricePerNightEur: 83, rating: 9.3, sqm: 100, distanceToBeachM: 350, propertyType: 'Villa',
    description: 'A large whitewashed villa with an infinity pool and sea view, 350m from the beach, sleeping up to 6, with a full outdoor kitchen.',
    reviews: [
      'The infinity pool view alone is worth it — best sunset spot of our whole trip. Outdoor kitchen made group dinners easy.',
      'Top end of our budget, but split between our group of 6 it worked out reasonably.',
    ],
  },
];

interface HotelHighlight {
  text: string;
  colorClass: string;
}

/** See WizardService.generateHonestReport / HonestReportGenerator (backend). */
interface HonestReport {
  pros: string[];
  cons: string[];
  summary: string;
}

/** Rating-for-price composite — same ratio computeHotelHighlight() uses for its own
 *  "best value" fallback claim, reused here to actually sort the list by it. Bug fixed
 *  2026-08-06: the results copy already claimed "sorted so the most relevant reasons show
 *  first", but MOCK_HOTELS was rendered in plain declaration order — no sort existed at all. */
function valueScore(hotel: MockHotel): number {
  return hotel.rating / hotel.pricePerNightEur;
}

/**
 * Deterministic, not AI — owner's explicit call: "to ne mora AI, to mozemo i mi da dodamo
 * engine". One superlative claimed per hotel where possible (cheapest/highest-rated/most-
 * spacious/closest-to-beach), assigned in priority order so no single listing hoards every
 * claim; anything left over gets a value-for-money framing instead of nothing.
 */
function computeHotelHighlight(hotel: MockHotel, all: MockHotel[], claimed: Set<string>): HotelHighlight {
  const isCheapest = hotel === all.reduce((a, b) => (b.pricePerNightEur < a.pricePerNightEur ? b : a));
  const isTopRated = hotel === all.reduce((a, b) => (b.rating > a.rating ? b : a));
  const isMostSpacious = hotel === all.reduce((a, b) => (b.sqm > a.sqm ? b : a));
  const isClosestToBeach = hotel === all.reduce((a, b) => (b.distanceToBeachM < a.distanceToBeachM ? b : a));

  if (isCheapest && !claimed.has('cheapest')) {
    claimed.add('cheapest');
    return { text: 'Of everything within budget, this is the cheapest per night.', colorClass: 'bg-emerald-100 text-emerald-800' };
  }
  if (isTopRated && !claimed.has('rated')) {
    claimed.add('rated');
    return { text: 'Highest guest rating of all matching properties.', colorClass: 'bg-amber-100 text-amber-800' };
  }
  if (isMostSpacious && !claimed.has('spacious')) {
    claimed.add('spacious');
    return { text: 'The most spacious option among the matches.', colorClass: 'bg-sky-100 text-sky-800' };
  }
  if (isClosestToBeach && !claimed.has('beach')) {
    claimed.add('beach');
    return { text: 'Closest to the beach of everything we found.', colorClass: 'bg-cyan-100 text-cyan-800' };
  }

  const valueScore = hotel.rating / hotel.pricePerNightEur;
  const isBestValue = hotel === all.reduce((a, b) => (b.rating / b.pricePerNightEur > a.rating / a.pricePerNightEur ? b : a));
  if (isBestValue && valueScore > 0) {
    return { text: 'Best rating-for-price balance in this list.', colorClass: 'bg-violet-100 text-violet-800' };
  }

  return { text: 'A solid all-around match for what you asked for.', colorClass: 'bg-slate-100 text-slate-700' };
}

@Component({
  selector: 'app-wizard',
  standalone: true,
  imports: [
    CommonModule,
    QuestionInputComponent,
    TravelersInputComponent,
    CitySearchComponent,
    AmenityPickerComponent,
    ButtonComponent,
    SpinnerComponent,
  ],
  templateUrl: './wizard.html',
})
export class WizardComponent implements OnInit {
  /** Anchor rendered right before the active (non-collapsed) step in the chat-scroll list —
   *  see wizard.html. Angular rebinds this ViewChild automatically as @for/@if change which
   *  element carries the template ref, since only one step is ever "active" at a time. */
  @ViewChild('activeStepAnchor') activeStepAnchor?: ElementRef<HTMLElement>;

  /** Dynamic (tag-matched) options for geography questions, keyed by question key. */
  readonly geographyOptions = signal<Record<string, TaxonomyNode[]>>({});

  /** Display label for the picked home city ("Berlin, DE") — city-search returns a real
   *  taxonomy_node id for the answer itself, but the chat-scroll summary row needs something
   *  human-readable to show once that step collapses, and geographyOptions never gets
   *  populated for home_city (it's typeahead-driven, not a suggestedGeography list). */
  readonly homeCityLabel = signal<string | null>(null);
  readonly finished = signal(false);

  /** Card currently under the pointer in the "screen 2" destination grid — drives the
   *  hover-preview panel in the reserved left column. Null shows nothing there. */
  readonly hoveredNode = signal<TaxonomyNode | null>(null);
  readonly showCalculatingTransition = signal(false);
  readonly calculatingMessageIndex = signal(0);
  readonly calculatingMessages = CALCULATING_MESSAGES;

  /** True while a geography sub-question (theme→country, country→city) is being (re)scoped. */
  readonly geographyLoading = signal<Record<string, boolean>>({});
  /** True while "Dalje" is persisting the step and possibly loading the next step's content. */
  readonly submitting = signal(false);

  /** Set from route data when this URL is a themed campaign entry point (see app.routes.ts) —
   *  the SINGLE place campaignKey is resolved from (a path segment today, could be a subdomain
   *  later without touching anything below this line). Null on the plain '' route, which
   *  behaves exactly as before. */
  private campaignKey: string | null = null;
  themeIntro: ThemeIntro | null = null;
  /** True while showing the themed landing, before the session has actually started — kept
   *  separate from wizard.loading() so we don't create a SearchSession for someone who never
   *  clicks past the intro. */
  readonly showIntro = signal(false);

  constructor(public wizard: WizardService, public auth: AuthService, private route: ActivatedRoute) {}

  /** Gates the AI-only free-text fields (smestaj_preference textarea + amenity picker's Big-NO
   *  side) — CLAUDE.md section 3/8, owner's ask 2026-08-11: these two are the only inputs that
   *  actually feed the AI layer (Big-YES drives real Booking filters and stays free always).
   *  Structured taxonomy pills are NEVER gated — only these two free-text signals. */
  get aiSearchEnabled(): boolean {
    const user = this.auth.currentUser();
    return !!user && (user.wallet?.balance ?? 0) > 0;
  }

  /** null when the AI fields are enabled; otherwise the explanation shown next to them. */
  get aiSearchGateMessage(): string | null {
    if (this.aiSearchEnabled) return null;
    if (!this.auth.loaded()) return null;

    return this.auth.currentUser()
      ? 'You’re out of AI credits.'
      : 'Log in to use Big NO and AI precise search.';
  }

  get aiSearchOutOfCredits(): boolean {
    return this.auth.loaded() && !!this.auth.currentUser() && !this.aiSearchEnabled;
  }

  async ngOnInit(): Promise<void> {
    const data = this.route.snapshot.data;
    this.campaignKey = (data['campaignKey'] as string) ?? null;
    this.themeIntro = (data['intro'] as ThemeIntro) ?? null;

    if (this.campaignKey && this.themeIntro) {
      this.showIntro.set(true);
      return;
    }

    await this.startWizard();
  }

  async startThemed(): Promise<void> {
    this.showIntro.set(false);
    await this.startWizard();
  }

  /** Mock results screen — see wizard_architecture memory, 2026-08-06. Not real Booking data. */
  readonly mockHotels = MOCK_HOTELS;

  /** Best-rating-for-price first, 2026-08-06 (owner's ask: "fokus na vrh liste") — the top
   *  entry also gets a distinct "Top pick" card treatment in the template, not just first
   *  position, so the payoff moment has an obvious focal point instead of ten equal-weight
   *  cards. */
  get sortedHotels(): MockHotel[] {
    return [...this.mockHotels].sort((a, b) => valueScore(b) - valueScore(a));
  }

  hotelHighlight(hotel: MockHotel): HotelHighlight {
    return computeHotelHighlight(hotel, this.mockHotels, this.claimedHighlights);
  }

  /** Reset once per component instance so re-computing highlights on every change-detection
   *  pass doesn't double-claim superlatives across calls — mockHotels never changes at
   *  runtime, so this only ever gets populated once in practice. */
  private readonly claimedHighlights = new Set<string>();

  /** AI-generated pros/cons per hotel, keyed by name — see WizardService.generateHonestReport
   *  and HonestReportGenerator (backend). 'loading' while in flight, null if it failed (best-
   *  effort — a missing Honest Report must never block the results screen from showing). */
  readonly honestReports = signal<Record<string, HonestReport | 'loading' | null>>({});

  honestReportFor(hotel: MockHotel): HonestReport | 'loading' | null {
    return this.honestReports()[hotel.name] ?? null;
  }

  /** Fired once when the results screen first shows (see goNext()) — all cards load in
   *  parallel, not sequentially, since GPT-4o-mini is fast/cheap enough that there's no need
   *  to ration it (see CLAUDE.md, "AI troškovi zanemarljivi"). */
  private async loadHonestReports(): Promise<void> {
    this.honestReports.update((current) => {
      const next = { ...current };
      for (const hotel of this.mockHotels) next[hotel.name] = 'loading';
      return next;
    });

    await Promise.all(
      this.mockHotels.map(async (hotel) => {
        const report = await this.wizard.generateHonestReport(hotel.name, hotel.description, hotel.reviews);
        this.honestReports.update((current) => ({ ...current, [hotel.name]: report }));
      })
    );
  }

  private async startWizard(): Promise<void> {
    await this.wizard.init(this.campaignKey ?? undefined);
    // Fire-and-forget — geo-IP lookup latency must never delay the visible wizard, see
    // WizardService.detectHomeCity docblock.
    void this.wizard.detectHomeCity();
    await this.loadGeographyForCurrentStep();
    this.prefillRecommendedDates();
    this.prefillDefaultAdultsCount();
  }

  get visibleQuestions(): WizardQuestion[] {
    const step = this.wizard.currentStep();
    if (!step) return [];
    return step.questions.filter(
      (q) =>
        this.wizard.isQuestionVisible(q) &&
        !TRAVELERS_QUESTION_KEYS.has(q.key) &&
        q.key !== ROOMS_QUESTION_KEY &&
        q.key !== HOME_CITY_QUESTION_KEY &&
        q.key !== AMENITY_YES_KEY &&
        q.key !== AMENITY_NO_KEY &&
        q.key !== SMESTAJ_AVOID_KEY
    );
  }

  /** "Mandatory question" as a first-class, extensible concept — owner's call, 2026-08-06:
   *  "ne moze proceed bez toga... ako nam nista ne kaze, ne mozemo nista da mu vratimo ko data,
   *  a da valja." Starts with total_budget (see the migration's docblock) but is driven purely
   *  by WizardQuestion.mandatory, not hardcoded per-key — more can be flagged later with zero
   *  frontend changes. Blocks BOTH nav paths on a step (plain Proceed, and the "stay together?"
   *  Yes/No that replaces it for groups >3) — both live on the same step as total_budget, and
   *  neither should be able to skip past an unanswered mandatory question. */
  get canProceed(): boolean {
    const step = this.wizard.currentStep();
    if (!step) return true;

    return step.questions
      .filter((q) => q.mandatory && this.wizard.isQuestionVisible(q))
      .every((q) => this.isAnswered(this.wizard.getAnswer(q.key)));
  }

  private isAnswered(value: unknown): boolean {
    if (value === null || value === undefined) return false;
    if (typeof value === 'string') return value.trim() !== '';
    if (Array.isArray(value)) return value.length > 0;
    return true;
  }

  /** True when the current step has the Big-YES amenity question — renders the combined
   *  picker widget instead of two separate generic pill grids. */
  get showAmenityPicker(): boolean {
    return !!this.wizard.currentStep()?.questions.some((q) => q.key === AMENITY_YES_KEY);
  }

  onAmenityYesChange(slugs: string[]): void {
    this.wizard.setAnswer(AMENITY_YES_KEY, slugs);
  }

  onAmenityNoChange(slugs: string[]): void {
    this.wizard.setAnswer(AMENITY_NO_KEY, slugs);
  }

  /**
   * Typed amenity text that matched nothing in the taxonomy — never silently lost, but routed
   * to a field matching its framing. Bug fixed 2026-08-04: both used to land in
   * smestaj_preference (a POSITIVE "wishlist" field), which reads backwards for something
   * typed into the avoid/NO box — "wishlist: Crowd, Loud" sounds like they're wanted.
   */
  onAmenityUnmatchedText({ text, isAvoid }: { text: string; isAvoid: boolean }): void {
    const field = isAvoid ? 'smestaj_avoid' : 'smestaj_preference';
    const existing = (this.wizard.getAnswer(field) as string) ?? '';
    this.wizard.setAnswer(field, existing ? `${existing}\n${text}` : text);
  }

  /** True when the current step has an adults_count question — its whole cluster
   *  (adults/children/crib) renders via the combined widget instead of the per-question loop. */
  get showTravelersWidget(): boolean {
    return !!this.wizard.currentStep()?.questions.some((q) => q.key === 'adults_count');
  }

  /** Drives <app-travelers-input>'s header — see TravelersInputComponent.adultsLabel docblock
   *  for the bug this fixes (seeder label edits silently doing nothing). */
  get adultsCountLabel(): string | undefined {
    return this.wizard.currentStep()?.questions.find((q) => q.key === 'adults_count')?.label;
  }

  onTravelersChange(value: TravelersValue): void {
    // A group of ≤3 never gets asked about rooms at all — silently defaults to 1. A group of
    // >3 gets the "stay together?" yes/no on this same step instead (see
    // showRoomsTogetherQuestion / onRoomsTogetherChoice) — don't overwrite an answer they may
    // have already given there if they tweak the headcount afterward and it's still >3.
    const total = (value.adultsCount ?? 0) + value.childrenAges.length;
    if (total > 0 && total <= 3) {
      this.wizard.setAnswer('number_of_rooms', 1);
    }

    this.wizard.setAnswer('adults_count', value.adultsCount);
    this.wizard.setAnswer('children_ages', value.childrenAges);
    this.wizard.setAnswer('needs_crib', value.needsCrib);

    this.autoSelectFamilyGroupType(value.childrenAges);
  }

  /** Adults + children mixed is a family, essentially always — owner's explicit ask,
   *  2026-08-03: auto-select instead of making the user click it. Only fires if group_type
   *  hasn't already been explicitly answered (never overwrites a real choice), and only once
   *  options have loaded (loadGeographyForCurrentStep already fetches them regardless of the
   *  question's current visibility, so they're normally ready before this ever runs). */
  private autoSelectFamilyGroupType(childrenAges: number[]): void {
    if (childrenAges.length === 0 || this.wizard.getAnswer('group_type')) {
      return;
    }

    const porodica = this.geographyOptions()['group_type']?.find((n) => n.slug === 'porodica');
    if (porodica) {
      this.wizard.setAnswer('group_type', porodica.id);
    }
  }

  get showCitySearch(): boolean {
    return !!this.wizard.currentStep()?.questions.some((q) => q.key === HOME_CITY_QUESTION_KEY);
  }

  async onHomeCitySelected(city: WorldCityResult): Promise<void> {
    await this.wizard.selectHomeCityFromWorldCity(city.id);
    this.homeCityLabel.set(`${city.name}, ${city.countryCode}`);
  }

  /** True only for a group >3 — ≤3 is silently defaulted to 1 room in onTravelersChange, never
   *  asked at all. See ROOMS_QUESTION_KEY. */
  get showRoomsTogetherQuestion(): boolean {
    const step = this.wizard.currentStep();
    return !!step?.questions.some((q) => q.key === ROOMS_QUESTION_KEY) && this.wizard.totalTravelers() > 3;
  }

  /** Yes -> everyone in one unit. No -> ceil(travelers / 3), owner's own rule of thumb for
   *  "how many rooms does a group this size realistically need." Answering either way also
   *  advances to the next step (this replaces the normal Nazad/Dalje pair while shown, see
   *  wizard.html) — no separate "Dalje" click needed. */
  async onRoomsTogetherChoice(together: boolean): Promise<void> {
    const rooms = together ? 1 : Math.ceil(this.wizard.totalTravelers() / 3);
    this.wizard.setAnswer(ROOMS_QUESTION_KEY, rooms);
    await this.goNext();
  }

  optionsFor(question: WizardQuestion): TaxonomyNode[] | null {
    const options = this.geographyOptions()[question.key] ?? null;
    if (!options) return options;

    // "Školski put" needs a real class-sized group of kids, not just any 3+ travelers —
    // owner's call, 2026-08-04: "ako ukucam 3 odrasla, to nije school trip, moze se pojavi tek
    // kad je dece 5+". Can't express a numeric threshold like this through the taxonomy
    // excludes/implies engine (that only relates two discrete taxonomy picks to each other),
    // so it's a plain frontend filter instead — same "contradictory -> don't show" principle,
    // just quantitative rather than relational.
    if (question.key === 'group_type') {
      const childrenCount = ((this.wizard.getAnswer('children_ages') as number[]) ?? []).length;
      let filtered = options;

      if (childrenCount < 5) {
        filtered = filtered.filter((o) => o.slug !== 'skola');
      }
      // "Ako ima dece, ne moze grupa penzionera da bude" — owner's call, 2026-08-04. Any
      // children at all rules this out, not just a headcount threshold like school trip.
      if (childrenCount > 0) {
        filtered = filtered.filter((o) => o.slug !== 'drustvo_penzionera');
      }

      return filtered;
    }

    return options;
  }

  isGeographyLoading(question: WizardQuestion): boolean {
    return !!this.geographyLoading()[question.key];
  }

  onAnswerChange(question: WizardQuestion, value: unknown): void {
    this.wizard.setAnswer(question.key, value);

    // Selecting a region theme or country immediately scopes the next geography question.
    if (question.key === 'region_theme') {
      this.loadGeography('country_region', 'country', value as string);
    }
    if (question.key === 'country_region') {
      this.loadGeography('city', 'city', value as string);
    }
  }

  async goNext(): Promise<void> {
    this.submitting.set(true);
    try {
      const prevStepKey = this.wizard.currentStep()?.key;
      await this.wizard.goNext();
      if (this.wizard.currentStepIndex() >= this.wizard.steps().length) {
        this.finished.set(true);
        void this.loadHonestReports();
        return;
      }

      const newStepKey = this.wizard.currentStep()?.key;
      // "Screen 1" -> "screen 2" boundary: all Q&A (smestaj is the last screen-1 step) just
      // finished, zemlja_regija (destination cards) is next. Owner's call, 2026-08-04: a
      // "calculating" transition here, not an instant swap — "zarolamo neki loader koji kao
      // nesto mnogo racuna". Only on the FORWARD crossing (prevStepKey check), not every time
      // this step happens to be current (e.g. re-editing something earlier and re-advancing).
      if (newStepKey === 'zemlja_regija' && prevStepKey === 'smestaj') {
        await this.runCalculatingTransition();
      } else {
        await this.loadGeographyForCurrentStep();
        this.prefillRecommendedDates();
        this.prefillDefaultAdultsCount();
        this.scrollToActiveStep();
      }
    } finally {
      this.submitting.set(false);
    }
  }

  /**
   * Real geography loading runs concurrently with a minimum-duration rotating-message overlay
   * — not fake work, just not letting genuinely fast loading (usually well under a second)
   * undercut the "we're figuring this out for you" moment. See CALCULATING_MESSAGES.
   */
  private async runCalculatingTransition(): Promise<void> {
    this.showCalculatingTransition.set(true);
    this.calculatingMessageIndex.set(0);
    const cycle = setInterval(() => {
      this.calculatingMessageIndex.update((i) => (i + 1) % CALCULATING_MESSAGES.length);
    }, 500);

    try {
      await Promise.all([
        this.loadGeographyForCurrentStep(),
        new Promise((resolve) => setTimeout(resolve, CALCULATING_MIN_DURATION_MS)),
      ]);
    } finally {
      clearInterval(cycle);
      this.showCalculatingTransition.set(false);
    }

    this.scrollToActiveStep();
  }

  /** "Screen 2" — country_region/city render as cards with a hover-preview instead of the
   *  generic pill grid. See DESTINATION_CARD_KEYS. */
  isDestinationCard(question: WizardQuestion): boolean {
    return DESTINATION_CARD_KEYS.has(question.key);
  }

  /** Both country_region and city store the node's `id` (session_field ends in `_id`) — same
   *  value convention QuestionInputComponent's usesSlugValue already encodes generically, just
   *  hardcoded here since this card grid only ever handles these two specific questions. */
  onDestinationCardSelect(question: WizardQuestion, node: TaxonomyNode): void {
    this.onAnswerChange(question, node.id);
  }

  isDestinationSelected(question: WizardQuestion, node: TaxonomyNode): boolean {
    return this.wizard.getAnswer(question.key) === node.id;
  }

  /** Reads the general-knowledge vibe/atmosphere writeup seeded onto the node's meta — see
   *  WizardSeeder::seedCityAndCountryVibeProfiles(), 2026-08-04. Null if this particular node
   *  hasn't been written up yet (not every taxonomy node has one). */
  vibeDescription(node: TaxonomyNode | null): string | null {
    const profile = node?.meta?.['vibe_profile'] as { description?: string } | undefined;
    return profile?.description ?? null;
  }

  /** Left-column fallback when nothing's hovered — see STEP_DESCRIPTIONS. Prepends the
   *  campaign-context blurb only on the very first step (visitedStepIndices().length === 1),
   *  since that's the one place a first-time viewer hasn't yet seen enough of the flow to
   *  infer it's campaign-scoped. */
  stepDescription(step: WizardStep): string {
    const own = STEP_DESCRIPTIONS[step.key] ?? '';
    const isFirstStep = this.wizard.visitedStepIndices().length === 1;

    return isFirstStep ? `${CAMPAIGN_INTRO_BLURB}\n\n${own}` : own;
  }

  /**
   * Pre-fills the date_range question with the system's already-computed recommended window
   * (termin_category's default window, resolved server-side — see
   * SearchSessionQueryCompiler::resolveDates()) instead of leaving the picker blank. Owner's
   * explicit ask, 2026-08-04. Only when the question is on the CURRENT step and not already
   * answered — never overwrites a real pick, same "suggest not force" rule as everywhere else.
   * compiledQuery is refreshed after every persisted step (see WizardService), so by the time
   * a date_range question is reached mid-campaign (termin_category is preset from session
   * start) the recommendation is already sitting there ready to read.
   */
  private prefillRecommendedDates(): void {
    const step = this.wizard.currentStep();
    const dateQuestion = step?.questions.find((q) => q.inputType === 'date_range');
    if (!dateQuestion || this.wizard.getAnswer(dateQuestion.key)) return;

    const bookingParams = this.wizard.compiledQuery()?.['bookingParams'] as
      | { checkin?: string; checkout?: string }
      | undefined;

    if (bookingParams?.checkin && bookingParams?.checkout) {
      this.wizard.setAnswer(dateQuestion.key, [bookingParams.checkin, bookingParams.checkout]);
    }
  }

  /** Owner's bug report, 2026-08-06: "ako ne dodam decu ili menjam broj adulta, ostaje 0" —
   *  the +/- widget only ever emits on a click, so a user who never touches it left
   *  adults_count unset, and it got persisted as null/0 instead of the obviously-intended "1
   *  adult, me." The template already displays a visual "1" fallback (adultsCount() ?? 1 in
   *  travelers-input.html), but that was cosmetic only — this makes it the real stored answer
   *  too, same "prefill, never overwrite a real pick" rule as prefillRecommendedDates. */
  private prefillDefaultAdultsCount(): void {
    if (this.showTravelersWidget && this.wizard.getAnswer('adults_count') == null) {
      this.wizard.setAnswer('adults_count', 1);
    }
  }

  goBack(): void {
    this.wizard.goBack();
    this.scrollToActiveStep();
  }

  /** Re-opens a completed (collapsed) step for editing — see WizardService.editStep for why
   *  truncating forward history is correct, not lossy. */
  editStep(index: number): void {
    this.wizard.editStep(index);
    this.scrollToActiveStep();
  }

  /** Waits a tick for the @for/@if to re-render (activeStepAnchor moves to the new active
   *  step) before scrolling — the chat-scroll UI's whole point is that the user never has to
   *  manually find the new question, see wizard_architecture memory, 2026-08-04.
   *
   *  Deliberately NOT plain `scrollIntoView({block: 'start'})` — that pins the anchor flush to
   *  the viewport's top edge, which pushes the just-collapsed previous step fully off-screen
   *  above it. Visually indistinguishable from the old full-page swap (bug reported 2026-08-04:
   *  "nije u skrol, ide na page 2"), even though it's technically one continuous page. Leaving
   *  a fixed offset keeps the tail of the collapsed history in view, so the transition actually
   *  reads as a scroll. */
  private scrollToActiveStep(): void {
    const HISTORY_PEEK_PX = 96;
    setTimeout(() => {
      const el = this.activeStepAnchor?.nativeElement;
      if (!el) return;
      const top = el.getBoundingClientRect().top + window.scrollY - HISTORY_PEEK_PX;
      window.scrollTo({ top: Math.max(top, 0), behavior: 'smooth' });
    }, 0);
  }

  /**
   * One-line, human-readable recap of a completed step's answers — shown in its collapsed
   * chat-scroll row (wizard.html). Deliberately simple: known consolidated widgets
   * (travelers/rooms/home_city) get a tailored phrase, everything else falls back to a
   * generic "Label: value" using the same option-label lookup the active step's inputs use.
   */
  stepSummary(step: WizardStep): string {
    const parts: string[] = [];

    if (step.questions.some((q) => TRAVELERS_QUESTION_KEYS.has(q.key))) {
      const adults = (this.wizard.getAnswer('adults_count') as number) ?? 0;
      const children = (this.wizard.getAnswer('children_ages') as number[]) ?? [];
      const adultLabel = adults > 1 ? 'adults' : 'adult';
      const childrenLabel = children.length > 1 ? 'children' : 'child';
      parts.push(children.length > 0 ? `${adults} ${adultLabel}, ${children.length} ${childrenLabel}` : `${adults} ${adultLabel}`);
    }

    if (step.questions.some((q) => q.key === ROOMS_QUESTION_KEY)) {
      const rooms = this.wizard.getAnswer(ROOMS_QUESTION_KEY) as number | undefined;

      if (rooms != null) {
        const roomLabel = rooms > 1 ? 'rooms' : 'room';
        parts.push(rooms === 1 ? 'Together in 1 unit' : `${rooms} ${roomLabel}`);
      }
    }

    if (step.questions.some((q) => q.key === HOME_CITY_QUESTION_KEY) && this.homeCityLabel()) {
      parts.push(this.homeCityLabel()!);
    }

    for (const question of step.questions) {
      if (
        TRAVELERS_QUESTION_KEYS.has(question.key) ||
        question.key === ROOMS_QUESTION_KEY ||
        question.key === HOME_CITY_QUESTION_KEY ||
        !this.wizard.isQuestionVisible(question)
      ) {
        continue;
      }

      const value = this.wizard.getAnswer(question.key);
      if (value === undefined || value === null || value === '') continue;

      parts.push(this.formatAnswer(question, value));
    }

    return parts.length > 0 ? parts.join(' • ') : '—';
  }

  private formatAnswer(question: WizardQuestion, value: unknown): string {
    if (question.inputType === 'boolean') return value ? 'Da' : 'Ne';
    if (question.inputType === 'taxonomy_choice') return this.optionLabel(question.key, value);
    if (question.inputType === 'taxonomy_multi_choice' && Array.isArray(value)) {
      return value.map((v) => this.optionLabel(question.key, v)).join(', ');
    }
    if (Array.isArray(value)) return value.join(', ');
    return String(value);
  }

  /**
   * Bug fixed 2026-08-06 (owner: chat bubbles showing raw slugs like "flegma" instead of
   * "Chillseeker"): multi-choice answers (persona_group, preference_tags, ...) store the
   * node's SLUG, not its id — see QuestionInputComponent.onMultiChoiceToggle. This used to
   * match on `id` only, which a slug never equals, so it silently fell through to displaying
   * the raw stored value. Matching on either field covers both value conventions used across
   * the app without needing to duplicate the per-field usesSlugValue logic here.
   */
  private optionLabel(questionKey: string, value: unknown): string {
    const found = this.geographyOptions()[questionKey]?.find((o) => o.id === value || o.slug === value);
    return found?.label ?? String(value);
  }

  /**
   * Every taxonomy_choice/taxonomy_multi_choice question is routed through suggestedGeography
   * (not the plain static `options` field), whatever its taxonomyType — implies/excludes can
   * apply to ANY taxonomy type now (a "Foodie" persona hides "Great food" from preference_tags,
   * not just geography), so this can't stay special-cased to termin/region/country/city steps
   * only. country/city additionally chase the region_theme -> country -> city parent chain.
   */
  private async loadGeographyForCurrentStep(): Promise<void> {
    const step = this.wizard.currentStep();
    if (!step) return;

    for (const question of step.questions) {
      if (!question.taxonomyType) continue;

      if (question.taxonomyType === 'country') {
        const chosenTheme = this.wizard.getAnswer('region_theme') as string | undefined;
        await this.loadGeography(question.key, 'country', chosenTheme);
      } else if (question.taxonomyType === 'city') {
        const chosenCountry = this.wizard.getAnswer('country_region') as string | undefined;
        await this.loadGeography(question.key, 'city', chosenCountry);
      } else {
        await this.loadGeography(question.key, question.taxonomyType);
      }
    }
  }

  private async loadGeography(questionKey: string, taxonomyType: string, parentId?: string): Promise<void> {
    this.geographyLoading.update((g) => ({ ...g, [questionKey]: true }));
    try {
      const options = await this.wizard.loadGeographyOptions(taxonomyType, parentId);
      this.geographyOptions.update((g) => ({ ...g, [questionKey]: options }));
    } finally {
      this.geographyLoading.update((g) => ({ ...g, [questionKey]: false }));
    }
  }
}
