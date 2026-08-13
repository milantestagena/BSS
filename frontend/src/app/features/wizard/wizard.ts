import { Component, ElementRef, OnInit, effect, signal, ViewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { WizardService } from '../../core/wizard.service';
import { AuthService } from '../../core/auth.service';
import { I18nService } from '../../core/i18n.service';
import { AppLocale, LocaleService } from '../../core/locale.service';
import { TaxonomyNode, WizardQuestion, WizardStep } from '../../core/wizard.types';
import { QuestionInputComponent } from './question-input';
import { TravelersInputComponent, TravelersValue } from './travelers-input';
import { CitySearchComponent, WorldCityResult } from './city-search';
import { AmenityPickerComponent } from './amenity-picker';
import { ButtonComponent } from '../../ui/button';
import { SpinnerComponent } from '../../ui/spinner';
import { InfoPopoverComponent } from '../../ui/info-popover';

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
 *  tip_smestaja/accommodation_facility/room_facility) instead of two separate generic pill
 *  grids — see amenity-picker.ts. Owner's design, 2026-08-04. */
const AMENITY_YES_KEY = 'amenities_yes';
const AMENITY_NO_KEY = 'amenities_no';

/** Same 3 types as amenity-picker.ts's own AMENITY_TYPES — duplicated here (not read from
 *  that component) because this class needs them in the SHARED geographyOptions map for
 *  optionLabel()/stepSummary(), not just inside the picker widget's own local state. Bug
 *  fixed 2026-08-13: amenities_yes/no questions have no `taxonomyType` of their own (they span
 *  3 types), so the generic per-step loader below always skipped them — the collapsed
 *  chat-bubble summary fell back to raw slugs ("klima" instead of the localized "Air
 *  conditioning"/"Klimaanlage"), which happened to look like real Serbian text so it read as
 *  "stuck in Serbian" even under the EN/DE toggle. */
const AMENITY_SUMMARY_TAXONOMY_TYPES = ['tip_smestaja', 'accommodation_facility', 'room_facility'];

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
const CALCULATING_MESSAGES: Record<AppLocale, string[]> = {
  en: [
    'Checking the weather forecast...',
    'Comparing accommodation prices...',
    'Finding beaches that suit you...',
    'Putting together your suggestions...',
  ],
  de: [
    'Wettervorhersage wird geprüft...',
    'Unterkunftspreise werden verglichen...',
    'Passende Strände werden gesucht...',
    'Deine Vorschläge werden zusammengestellt...',
  ],
};
const CALCULATING_MIN_DURATION_MS = 1800;

/** Idiot-proof, plain-English "why are we asking this" blurb per wizard step, shown in the
 *  reserved left column (see stepDescription()) — owner's ask, 2026-08-06, aimed at a reviewer
 *  (e.g. the Booking Affiliate application) who's never seen this flow before and needs to
 *  follow along without guessing. Keyed by WizardStep.key, same keys used by both the generic
 *  flow and every campaign (campaigns only ever reorder/select from this same fixed step set,
 *  see WizardSeeder::seedWizardSteps()). */
const STEP_DESCRIPTIONS: Record<AppLocale, Record<string, string>> = {
  en: {
    trip_type: 'What kind of trip is this? This one choice shapes every question that follows.',
    broj_putnika: "Just headcount and a rough budget for now — how many of you, any kids, and what you're comfortable spending. We'll match destinations to this later.",
    odakle_putujes: 'Your home city, so we can give you a realistic sense of how far each suggestion actually is.',
    termin: "When you're planning to travel. We already suggest a window based on the campaign, but you can fine-tune the exact dates.",
    persona: "A quick read on what kind of traveler(s) you are — this steers which destinations and vibes we suggest next.",
    preferencije: "What matters most about the trip's atmosphere, plus your nightly budget — helps us narrow things down to a shortlist that actually fits.",
    zemlja_regija: "Based on everything so far, here are the countries/regions that fit best. Pick one, or tell us if none of them feel right.",
    grad: 'Now narrowing down to a specific city or resort town within that region.',
    smestaj: "Last step — the specific things that would make (or break) your stay: amenities, must-haves, deal-breakers.",
  },
  de: {
    trip_type: 'Was für eine Reise soll es werden? Diese eine Wahl bestimmt alle folgenden Fragen.',
    broj_putnika: 'Erstmal nur die Kopfzahl und ein grobes Budget — wie viele seid ihr, gibt es Kinder, und was möchtet ihr ausgeben. Passende Ziele finden wir später.',
    odakle_putujes: 'Deine Heimatstadt, damit wir dir realistisch zeigen können, wie weit jeder Vorschlag tatsächlich entfernt ist.',
    termin: 'Wann du reisen möchtest. Wir schlagen bereits einen Zeitraum basierend auf der Kampagne vor, du kannst die genauen Daten aber anpassen.',
    persona: 'Ein kurzer Eindruck davon, was für ein Reisetyp du bist — das steuert, welche Ziele und Stimmungen wir als Nächstes vorschlagen.',
    preferencije: 'Was dir bei der Atmosphäre der Reise am wichtigsten ist, plus dein nächtliches Budget — hilft uns, eine wirklich passende Auswahl zu treffen.',
    zemlja_regija: 'Basierend auf allem bisher Gesagten sind das die am besten passenden Länder/Regionen. Wähle eins, oder sag uns, wenn keins passt.',
    grad: 'Jetzt grenzen wir es auf eine konkrete Stadt oder einen Ferienort innerhalb dieser Region ein.',
    smestaj: 'Letzter Schritt — die konkreten Dinge, die deinen Aufenthalt ausmachen (oder ruinieren): Ausstattung, Must-haves, Ausschlusskriterien.',
  },
};

/** Shown ABOVE the first step's description only, 2026-08-06 (owner's ask) — orients a
 *  first-time viewer to the fact that this whole flow is scoped to ONE campaign at a time
 *  before they've seen enough of it to infer that themselves. */
const CAMPAIGN_INTRO_BLURB: Record<AppLocale, string> = {
  en: "This flow is built around one campaign at a time. Right now you're looking at \"Late Summer\" — squeezing in warm-weather travel before the season ends. More campaigns are planned down the line (city breaks, holiday trips, full summer/winter vacations), each with its own tailored flow like this one.",
  de: 'Dieser Ablauf ist immer auf eine Kampagne zugeschnitten. Gerade siehst du "Spätsommer" — noch etwas warmes Reisewetter mitnehmen, bevor die Saison endet. Weitere Kampagnen sind geplant (Städtereisen, Feiertagsreisen, komplette Sommer-/Winterurlaube), jede mit ihrem eigenen, passenden Ablauf wie diesem.',
};

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
const HIGHLIGHT_TEXT: Record<AppLocale, Record<'cheapest' | 'rated' | 'spacious' | 'beach' | 'value' | 'fallback', string>> = {
  en: {
    cheapest: 'Of everything within budget, this is the cheapest per night.',
    rated: 'Highest guest rating of all matching properties.',
    spacious: 'The most spacious option among the matches.',
    beach: 'Closest to the beach of everything we found.',
    value: 'Best rating-for-price balance in this list.',
    fallback: 'A solid all-around match for what you asked for.',
  },
  de: {
    cheapest: 'Von allem innerhalb deines Budgets ist das die günstigste Option pro Nacht.',
    rated: 'Höchste Gästebewertung aller passenden Unterkünfte.',
    spacious: 'Die geräumigste Option unter den Treffern.',
    beach: 'Am nächsten zum Strand von allem, was wir gefunden haben.',
    value: 'Bestes Verhältnis von Bewertung zu Preis in dieser Liste.',
    fallback: 'Eine solide Rundum-Option für das, was du gesucht hast.',
  },
};

function computeHotelHighlight(hotel: MockHotel, all: MockHotel[], claimed: Set<string>, locale: AppLocale): HotelHighlight {
  const text = HIGHLIGHT_TEXT[locale];
  const isCheapest = hotel === all.reduce((a, b) => (b.pricePerNightEur < a.pricePerNightEur ? b : a));
  const isTopRated = hotel === all.reduce((a, b) => (b.rating > a.rating ? b : a));
  const isMostSpacious = hotel === all.reduce((a, b) => (b.sqm > a.sqm ? b : a));
  const isClosestToBeach = hotel === all.reduce((a, b) => (b.distanceToBeachM < a.distanceToBeachM ? b : a));

  if (isCheapest && !claimed.has('cheapest')) {
    claimed.add('cheapest');
    return { text: text.cheapest, colorClass: 'bg-emerald-100 text-emerald-800' };
  }
  if (isTopRated && !claimed.has('rated')) {
    claimed.add('rated');
    return { text: text.rated, colorClass: 'bg-amber-100 text-amber-800' };
  }
  if (isMostSpacious && !claimed.has('spacious')) {
    claimed.add('spacious');
    return { text: text.spacious, colorClass: 'bg-sky-100 text-sky-800' };
  }
  if (isClosestToBeach && !claimed.has('beach')) {
    claimed.add('beach');
    return { text: text.beach, colorClass: 'bg-cyan-100 text-cyan-800' };
  }

  const valueScore = hotel.rating / hotel.pricePerNightEur;
  const isBestValue = hotel === all.reduce((a, b) => (b.rating / b.pricePerNightEur > a.rating / a.pricePerNightEur ? b : a));
  if (isBestValue && valueScore > 0) {
    return { text: text.value, colorClass: 'bg-violet-100 text-violet-800' };
  }

  return { text: text.fallback, colorClass: 'bg-slate-100 text-slate-700' };
}

@Component({
  selector: 'app-wizard',
  standalone: true,
  imports: [
    CommonModule,
    RouterLink,
    QuestionInputComponent,
    TravelersInputComponent,
    CitySearchComponent,
    AmenityPickerComponent,
    ButtonComponent,
    SpinnerComponent,
    InfoPopoverComponent,
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

  get calculatingMessages(): string[] {
    return CALCULATING_MESSAGES[this.locale.locale()];
  }

  /** True while a geography sub-question (theme→country, country→city) is being (re)scoped. */
  readonly geographyLoading = signal<Record<string, boolean>>({});
  /** True while "Dalje" is persisting the step and possibly loading the next step's content. */
  readonly submitting = signal(false);

  /** Set from route data when this URL is a themed campaign entry point (see app.routes.ts) —
   *  the SINGLE place campaignKey is resolved from (a path segment today, could be a subdomain
   *  later without touching anything below this line). Null on the plain '' route, which
   *  behaves exactly as before. */
  private campaignKey: string | null = null;
  private themeIntroData: Record<AppLocale, ThemeIntro> | null = null;

  /** Locale-reactive — see themeIntroData/ngOnInit. Switching EN/DE mid-intro-screen updates
   *  this immediately, same as everything else translated in this component. */
  get themeIntro(): ThemeIntro | null {
    return this.themeIntroData?.[this.locale.locale()] ?? null;
  }
  /** True while showing the themed landing, before the session has actually started — kept
   *  separate from wizard.loading() so we don't create a SearchSession for someone who never
   *  clicks past the intro. */
  readonly showIntro = signal(false);

  /** Skips the effect's first (constructor-time) run — locale.locale() already reflects the
   *  right value for ngOnInit's own initial fetch, a second fetch right away would be wasted. */
  private isFirstLocaleEffect = true;

  /** Set the moment the user touches total_budget themselves (typed, or +/- stepper) — once
   *  true, syncDefaultBudget() stops overwriting it, no matter what else changes on the step
   *  afterward. Reset per session in startWizard(). */
  private budgetManuallyEdited = false;

  constructor(
    public wizard: WizardService,
    public auth: AuthService,
    public i18n: I18nService,
    public locale: LocaleService,
    private route: ActivatedRoute
  ) {
    // Owner's ask, 2026-08-11 ("ne menja se sve na promenu jezika, a mora") — backend-sourced
    // step/question/option labels are fetched once and cached in WizardService/geographyOptions,
    // so flipping the EN/DE toggle alone only re-renders the static i18n strings. This re-fetches
    // the backend content too, in place, without touching session/answers/current step.
    effect(() => {
      this.locale.locale();

      if (this.isFirstLocaleEffect) {
        this.isFirstLocaleEffect = false;
        return;
      }

      if (!this.wizard.sessionId()) return;

      void this.wizard.refreshLabels().then(() => this.loadGeographyForAllVisitedSteps());
    });
  }

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

    return this.auth.currentUser() ? this.i18n.t('outOfCredits') : this.i18n.t('loginForAiSearch');
  }

  get aiSearchOutOfCredits(): boolean {
    return this.auth.loaded() && !!this.auth.currentUser() && !this.aiSearchEnabled;
  }

  /** Owner's call, 2026-08-12: the "Data we collected so far" debug panel is a real testing
   *  tool (caught several real bugs this way), but a raw JSON blob reads as unfinished to a
   *  normal visitor or an affiliate reviewer. Hidden by default now, opt-in via `?debug=1` in
   *  the URL rather than removed outright — still one request away when actually needed. */
  get debugEnabled(): boolean {
    return this.route.snapshot.queryParamMap.get('debug') === '1';
  }

  async ngOnInit(): Promise<void> {
    const data = this.route.snapshot.data;
    this.campaignKey = (data['campaignKey'] as string) ?? null;
    this.themeIntroData = (data['intro'] as Record<AppLocale, ThemeIntro>) ?? null;

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
    return computeHotelHighlight(hotel, this.mockHotels, this.claimedHighlights, this.locale.locale());
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
   *  to ration it (see CLAUDE.md, "AI troškovi zanemarljivi").
   *
   *  Bug fixed 2026-08-11 (owner caught live: "odoshe 10 kredita na 1 pretragu... a nije nista
   *  upisano") — this used to call generateHonestReport unconditionally for every mock hotel,
   *  burning a credit each (see AiCreditsDirective), even when the user typed NOTHING into the
   *  AI-only fields. Matches the owner's original design (2026-08-09): the AI layer should only
   *  run at all when there's a real signal (smestaj_preference wishlist notes or the Big-NO
   *  avoid notes/picks) for it to act on — otherwise the structured taxonomy results ARE the
   *  answer, for free, no AI needed. */
  private async loadHonestReports(): Promise<void> {
    if (!this.hasAdvancedSearchSignals()) {
      return;
    }

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

  /** True when the user actually gave the AI something to act on — see loadHonestReports(). */
  private hasAdvancedSearchSignals(): boolean {
    const wishlist = (this.wizard.getAnswer('smestaj_preference') as string) ?? '';
    const avoidNotes = (this.wizard.getAnswer('smestaj_avoid') as string) ?? '';
    const avoidPicks = (this.wizard.getAnswer('amenities_no') as string[]) ?? [];

    return wishlist.trim() !== '' || avoidNotes.trim() !== '' || avoidPicks.length > 0;
  }

  /**
   * Top-10 "Best choices" cities from the wizard's own City step (now possibly spanning several
   * selected countries, see selectedCountryIds), re-surfaced on the results screen so the
   * traveler can jump between them without walking back through the whole flow — owner's ask,
   * 2026-08-12 ("nek prebaci Best Choices", cap bumped from 5 to 10 as "5 je malo za prenos").
   * Only the TOP matched-tag tier carries over, not "also/less good" ones — reuses whatever
   * `geographyOptions['city']` already holds rather than re-querying suggestedGeography.
   */
  get resultsCityChoices(): TaxonomyNode[] {
    const cities = this.geographyOptions()['city'] ?? [];
    const bestCount = Math.max(0, ...cities.map((n) => n.matchedTags?.length ?? 0));
    const best = bestCount > 0 ? cities.filter((n) => (n.matchedTags?.length ?? 0) === bestCount) : cities;

    return [...best].sort((a, b) => (a.priceRank ?? 99) - (b.priceRank ?? 99)).slice(0, 10);
  }

  /** Locally-selected chip on the results screen, defaulting to whatever city the session is
   *  currently on — not yet committed until switchResultsCity() actually runs. */
  readonly selectedResultsCityId = signal<string | null>(null);

  isResultsCitySelected(node: TaxonomyNode): boolean {
    return (this.selectedResultsCityId() ?? (this.wizard.getAnswer('city') as string | undefined)) === node.id;
  }

  /** Re-runs the results screen against a different shortlisted city — same session, no wizard
   *  steps re-walked. Resets honestReports so stale text from the OLD city's context never
   *  lingers under the new selection while the fresh ones load. */
  async searchResultsCity(): Promise<void> {
    const cityId = this.selectedResultsCityId();
    if (!cityId) return;

    this.wizard.loading.set(true);
    try {
      await this.wizard.switchResultsCity(cityId);
      this.honestReports.set({});
      await this.loadHonestReports();
    } finally {
      this.wizard.loading.set(false);
    }
  }

  private async startWizard(): Promise<void> {
    this.budgetManuallyEdited = false;
    await this.wizard.init(this.campaignKey ?? undefined);
    // Fire-and-forget — geo-IP lookup latency must never delay the visible wizard, see
    // WizardService.detectHomeCity docblock.
    void this.wizard.detectHomeCity();
    const firstStepKey = this.wizard.currentStep()?.key;
    if (firstStepKey) void this.wizard.recordEvent('step_viewed', { stepKey: firstStepKey });
    await this.loadGeographyForCurrentStep();
    this.prefillRecommendedDates();
    this.prefillDefaultAdultsCount();
    this.syncDefaultBudget();
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

  /** Drives the single "* Required" legend at the bottom of the step — owner's ask, 2026-08-13:
   *  a "(required)" aside next to every mandatory field's own label was redundant with the *
   *  and took up width; one legend line covers all of them at once. */
  get hasMandatoryQuestionOnStep(): boolean {
    return this.visibleQuestions.some((q) => q.mandatory);
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
    this.syncDefaultBudget();
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

  /**
   * "Screen 2" destination cards grouped by shared matched preference tags (see
   * GeographyResolver::assignPriceRanks/matchedTags docblocks), highest match count first —
   * owner's call, 2026-08-11: "cilj je da misli da mu tražimo savršeni smeštaj... zato treba da
   * narrow down listu", not present an exhaustive ranked list. Backend already hides zero-match
   * nodes when the traveler stated a preference, so a "0 matches" group here only ever appears
   * in the deliberate fallback case (this region's atmosphere/drinks/food tags aren't seeded
   * yet) — labeled generically rather than left blank.
   */
  /** slug -> translated label lookup for preference_tags, shared by groupedDestinations()'s
   *  group headers and matchedTagLabelsFor()'s per-card labels. */
  private preferenceTagLabels(): Map<string, string> {
    const tagLabels = new Map<string, string>();
    for (const tag of this.geographyOptions()['preference_tags'] ?? []) {
      tagLabels.set(tag.slug, tag.label);
    }
    return tagLabels;
  }

  /**
   * Owner's catch, 2026-08-12: a group header shows the UNION of tags matched across every card
   * in it (e.g. "Great beaches, Off the beaten path" when Ayia Napa matched only the first and
   * Paphos only the second) — read as if EVERY card in the group satisfies EVERY listed tag,
   * which isn't true once a group holds cards that tied on match COUNT but not on which tags.
   * This is the per-card correction: exactly which tag(s) THIS card matched, shown as a small
   * caption under its name.
   */
  matchedTagLabelsFor(node: TaxonomyNode): string {
    const tagLabels = this.preferenceTagLabels();
    return (node.matchedTags ?? []).map((slug) => tagLabels.get(slug)).filter(Boolean).join(', ');
  }

  /** 2-letter code for the City-step country badge (see wizard.html) — falls back to the full
   *  label for any country missing WizardSeeder's iso_code meta, so a gap here degrades to the
   *  old (verbose but correct) behavior rather than showing blank. */
  countryCodeFor(parent: { label: string; meta?: Record<string, unknown> | null }): string {
    return (parent.meta?.['iso_code'] as string | undefined) ?? parent.label;
  }

  /**
   * Group headers used to spell out the UNION of tags matched across every card in the group —
   * with 3+ tags selected that union crept toward listing all of them for EVERY tier, reading as
   * near-identical text between tiers (owner's catch, 2026-08-12). The per-card caption
   * (matchedTagLabelsFor) already carries the specific "why" per city, so the group header's only
   * real job is relative standing: top-matching tier is "Best choices", second is "Also good
   * choices", and — owner's follow-up catch, same day: with 3+ distinct match-count tiers,
   * "Also good choices" was repeating itself header-to-header with nothing to tell them apart —
   * every tier from the THIRD down is collapsed into one final "Less good choices" group instead
   * of getting its own repeated header. Caps at 3 distinct matched-tier headers, however many
   * distinct match counts actually exist.
   */
  groupedDestinations(question: WizardQuestion): { headerLabel: string; nodes: TaxonomyNode[] }[] {
    const nodes = this.optionsFor(question) ?? [];
    const byPrice = (a: TaxonomyNode, b: TaxonomyNode) => (a.priceRank ?? 99) - (b.priceRank ?? 99);

    const byCount = new Map<number, TaxonomyNode[]>();
    for (const node of nodes) {
      const count = node.matchedTags?.length ?? 0;
      const bucket = byCount.get(count) ?? [];
      bucket.push(node);
      byCount.set(count, bucket);
    }

    const counts = Array.from(byCount.keys()).sort((a, b) => b - a);
    const matchedCounts = counts.filter((c) => c > 0);
    // Owner's call, 2026-08-11: a single "Other options" group spanning EVERYTHING isn't
    // grouping anyone by anything — there's nothing to differentiate it from, so the header is
    // just noise. Only label the 0-match bucket when it's sitting alongside real matched groups.
    const onlyZeroMatchGroup = counts.length === 1 && counts[0] === 0;

    const groups: { headerLabel: string; nodes: TaxonomyNode[] }[] = [];

    matchedCounts.forEach((count, tierIndex) => {
      // Cheapest-first within the group — owner's ask, 2026-08-12: color alone ("it IS ordered")
      // wasn't legible as an order when the cards themselves stayed in an unrelated position.
      const tierNodes = [...byCount.get(count)!].sort(byPrice);

      if (tierIndex === 0) {
        groups.push({ headerLabel: this.i18n.t('bestChoicesHeader'), nodes: tierNodes });
      } else if (tierIndex === 1) {
        groups.push({ headerLabel: this.i18n.t('alsoGoodChoicesHeader'), nodes: tierNodes });
      } else if (tierIndex === 2) {
        groups.push({ headerLabel: this.i18n.t('lessGoodChoicesHeader'), nodes: tierNodes });
      } else {
        // 4th+ tier — merge straight into the "Less good choices" group already pushed above,
        // re-sorting so the combined group stays cheapest-first as a whole, not tier-then-price.
        const lessGood = groups[2];
        lessGood.nodes = [...lessGood.nodes, ...tierNodes].sort(byPrice);
      }
    });

    if (counts.includes(0)) {
      const zeroNodes = [...byCount.get(0)!].sort(byPrice);
      groups.push({ headerLabel: onlyZeroMatchGroup ? '' : this.i18n.t('otherOptionsHeader'), nodes: zeroNodes });
    }

    return groups;
  }

  /** True if ANY node within this specific group has a priceRank — the legend line renders per
   *  group (owner's ask, 2026-08-12: "ispod opisa" — right under that group's header, since a
   *  single legend way at the bottom of a long, undifferentiated group read as disconnected
   *  from the actual cheaper->pricier order the cards are already in). */
  groupHasPriceRanks(group: { nodes: TaxonomyNode[] }): boolean {
    return group.nodes.some((n) => !!n.priceRank);
  }

  /** Relative price coloring for a destination card — green (priceRank 1, cheapest of the
   *  currently-shown options) through red (5, priciest). Empty string (no coloring) when
   *  priceRank is null — not enough price data yet to rank. */
  priceRankClass(node: TaxonomyNode): string {
    const classes: Record<number, string> = {
      1: 'border-l-[10px] border-l-emerald-500',
      2: 'border-l-[10px] border-l-lime-500',
      3: 'border-l-[10px] border-l-amber-500',
      4: 'border-l-[10px] border-l-orange-500',
      5: 'border-l-[10px] border-l-red-500',
    };

    return node.priceRank ? classes[node.priceRank] : '';
  }

  /** False when groupedDestinations() collapsed to a single, unlabeled fallback group — in
   *  that case nothing is actually grouped, so the "grouped by..." intro line would be
   *  misleading too (same reasoning as the suppressed "Other options" header). */
  hasRealGrouping(question: WizardQuestion): boolean {
    const groups = this.groupedDestinations(question);
    return !(groups.length === 1 && groups[0].headerLabel === '');
  }

  isGeographyLoading(question: WizardQuestion): boolean {
    return !!this.geographyLoading()[question.key];
  }

  onAnswerChange(question: WizardQuestion, value: unknown): void {
    // Owner's ask, 2026-08-13: once they've touched total_budget themselves (typed a value or
    // clicked the +/- stepper), the auto-computed default must stop overwriting it — even if
    // adults_count/group_type/etc. change again afterward on the same step.
    if (question.key === 'total_budget') {
      this.budgetManuallyEdited = true;
    }

    this.wizard.setAnswer(question.key, value);

    // Selecting a region theme or country immediately scopes the next geography question.
    if (question.key === 'region_theme') {
      this.loadGeography('country_region', 'country', value as string);
    }
    if (question.key === 'country_region') {
      // Multi-select, 2026-08-12 — gathers cities from ANY of the selected countries.
      void this.loadGeography('city', 'city', undefined, this.selectedCountryIds());
    }

    // Recompute the default budget as the group is picked, etc. — no-ops once
    // budgetManuallyEdited is set, or if total_budget isn't on this step. Harmless to call for
    // every answer on every step (syncDefaultBudget checks both internally).
    this.syncDefaultBudget();
  }

  async goNext(): Promise<void> {
    this.submitting.set(true);
    try {
      const prevStepKey = this.wizard.currentStep()?.key;
      await this.wizard.goNext();
      if (this.wizard.currentStepIndex() >= this.wizard.steps().length) {
        this.finished.set(true);
        // Owner's ask, 2026-08-11 ("skrol nije na top page, a to je must") — the chat-scroll
        // flow leaves the page scrolled wherever the last question was; the results screen is a
        // different "page" entirely and must always open at the top.
        window.scrollTo(0, 0);
        void this.wizard.recordEvent('results_reached');
        void this.loadHonestReports();
        return;
      }

      const newStepKey = this.wizard.currentStep()?.key;
      if (newStepKey) void this.wizard.recordEvent('step_viewed', { stepKey: newStepKey });
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
        this.syncDefaultBudget();
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
      this.calculatingMessageIndex.update((i) => (i + 1) % this.calculatingMessages.length);
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

  /**
   * `city` is still single-select (stores the node's `id` directly). `country_region` became
   * multi-select, 2026-08-12 (owner's ask) — toggles the node's SLUG in an array instead
   * (matching the persona_tags/preference_tags multi-choice convention, since its session_field
   * no longer ends in `_id`), so onAnswerChange's implies/excludes pipeline resolves it exactly
   * like any other free_text_answers.* multi-choice field.
   */
  onDestinationCardSelect(question: WizardQuestion, node: TaxonomyNode): void {
    if (question.key === 'country_region') {
      const current = (this.wizard.getAnswer('country_region') as string[] | undefined) ?? [];
      const next = current.includes(node.slug) ? current.filter((s) => s !== node.slug) : [...current, node.slug];
      this.onAnswerChange(question, next);
      return;
    }

    this.onAnswerChange(question, node.id);
  }

  isDestinationSelected(question: WizardQuestion, node: TaxonomyNode): boolean {
    if (question.key === 'country_region') {
      return ((this.wizard.getAnswer('country_region') as string[] | undefined) ?? []).includes(node.slug);
    }

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
    const locale = this.locale.locale();
    const own = STEP_DESCRIPTIONS[locale][step.key] ?? '';
    const isFirstStep = this.wizard.visitedStepIndices().length === 1;

    return isFirstStep ? `${CAMPAIGN_INTRO_BLURB[locale]}\n\n${own}` : own;
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

  /** Owner's ask, 2026-08-13: "kad izabere tip ljudi... u potrosnju stavimo nesto tipa 400 po
   *  odraslom - 300 po detetu" — keeps total_budget synced to headcount, using rates from
   *  WizardCampaign.meta (never hardcoded, so a future campaign can set its own numbers — "da
   *  mozemo da podesimo po kampanji"). adults_count/group_type/total_budget all live on the
   *  SAME step (broj_putnika), so this re-runs live off onTravelersChange/onAnswerChange as the
   *  user answers each one in turn, not just once on step load — "update sumu nakon sto izabere
   *  koja grupa pripada" (2026-08-13 follow-up). Stops touching the field entirely once
   *  budgetManuallyEdited is set (the user typed a value or used the +/- stepper themselves) —
   *  "ako je rucno nesto menjao, vise ne prihvataj promene". No-ops if the campaign hasn't
   *  configured a per-adult rate, or total_budget isn't a question on the current step. */
  private syncDefaultBudget(): void {
    if (this.budgetManuallyEdited) return;

    const step = this.wizard.currentStep();
    if (!step?.questions.some((q) => q.key === 'total_budget')) return;

    const meta = this.wizard.campaignMeta();
    const perAdult = meta?.['default_budget_per_adult_eur'] as number | undefined;
    if (typeof perAdult !== 'number') return;
    const perChild = (meta?.['default_budget_per_child_eur'] as number | undefined) ?? 0;

    const adults = (this.wizard.getAnswer('adults_count') as number) ?? 0;
    const children = ((this.wizard.getAnswer('children_ages') as number[]) ?? []).length;
    if (adults === 0 && children === 0) return;

    this.wizard.setAnswer('total_budget', adults * perAdult + children * perChild);
  }

  goBack(): void {
    this.wizard.goBack();
    this.scrollToActiveStep();
  }

  /**
   * Owner's ask, 2026-08-12: from the results screen, a way back to the chat that ISN'T "pick a
   * different city" — sometimes the tweak needed is small (a "Change" on some earlier step), not
   * a whole new destination.
   *
   * Bug fixed same day: flipping `finished` alone left the City step rendering blank — reaching
   * the results screen pushes an out-of-bounds index onto visitedStepIndices (see goNext's
   * `currentStepIndex() >= steps().length` branch), so the wizard's OWN state still pointed past
   * the last real step even though this component's `finished` flag said otherwise. Reuses
   * goBack()'s existing "step back to the second-to-last visited index" logic instead of
   * touching visitedStepIndices directly, so this stays correct if that mechanism ever changes.
   */
  backToSession(): void {
    this.finished.set(false);
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
      const adultLabel = this.i18n.t(adults > 1 ? 'adultsPlural' : 'adultSingular');
      const childrenLabel = this.i18n.t(children.length > 1 ? 'childrenCount' : 'childCount');
      parts.push(children.length > 0 ? `${adults} ${adultLabel}, ${children.length} ${childrenLabel}` : `${adults} ${adultLabel}`);
    }

    if (step.questions.some((q) => q.key === ROOMS_QUESTION_KEY)) {
      const rooms = this.wizard.getAnswer(ROOMS_QUESTION_KEY) as number | undefined;

      if (rooms != null) {
        const roomLabel = this.i18n.t(rooms > 1 ? 'roomsPlural' : 'roomSingular');
        parts.push(rooms === 1 ? this.i18n.t('togetherOneUnit') : `${rooms} ${roomLabel}`);
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

    return parts.length > 0 ? parts.join(' • ') : this.i18n.t('noAnswer');
  }

  private formatAnswer(question: WizardQuestion, value: unknown): string {
    if (question.inputType === 'boolean') return this.i18n.t(value ? 'yes' : 'no');
    if (question.inputType === 'taxonomy_choice') return this.optionLabel(question.key, value);
    if (question.inputType === 'taxonomy_multi_choice' && Array.isArray(value)) {
      return value.map((v) => this.optionLabel(question.key, v)).join(', ');
    }
    if (Array.isArray(value)) return value.join(', ');
    // Owner's ask, 2026-08-11: the chat-bubble summary showed a bare number ("800") for the
    // budget question — needs the currency unit, all amounts in this app are EUR.
    if (question.key === 'total_budget') return `${value} EUR`;
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

    await this.loadGeographyForStep(step);
  }

  /**
   * Re-fetches options for every taxonomy_choice/multi_choice question on EVERY already-visited
   * step, not just the current one — needed on a locale switch (see the constructor's locale
   * effect), since a PAST step's collapsed chat-bubble summary reads `optionLabel()` against
   * whatever `geographyOptions` entry that step last loaded, which loadGeographyForCurrentStep()
   * alone would never touch again once the user has moved past it (owner caught this live: past
   * steps stayed in the old language after switching EN/DE). Sequential, not parallel — this is
   * only triggered by an explicit language switch, not the hot path, and staying sequential
   * avoids hammering the backend with a burst of concurrent requests for a rare action.
   */
  private async loadGeographyForAllVisitedSteps(): Promise<void> {
    for (const index of this.wizard.visitedStepIndices()) {
      const step = this.wizard.steps()[index];
      if (step) await this.loadGeographyForStep(step);
    }
  }

  private async loadGeographyForStep(step: WizardStep): Promise<void> {
    if (step.questions.some((q) => q.key === AMENITY_YES_KEY || q.key === AMENITY_NO_KEY)) {
      await this.loadAmenitySummaryOptions();
    }

    // Two questions on the same step can share a plain taxonomyType — e.g. the "Traveler type"
    // step's single-choice `persona` and multi-choice `persona_group` both read taxonomyType
    // 'persona' — which used to fire the identical suggestedGeography query twice in a row
    // (owner's catch, 2026-08-12: "kao da ga 2 puta loaduje"). Reuse the first fetch instead of
    // re-querying. Only applies to the plain branch below — country/city are keyed by a dynamic
    // parentId (region_theme / country_region), so they're never actually redundant with each
    // other the way two flat 'persona' questions are.
    const fetchedByTaxonomyType = new Map<string, TaxonomyNode[]>();

    for (const question of step.questions) {
      if (!question.taxonomyType) continue;

      if (question.taxonomyType === 'country') {
        const chosenTheme = this.wizard.getAnswer('region_theme') as string | undefined;
        await this.loadGeography(question.key, 'country', chosenTheme);
      } else if (question.taxonomyType === 'city') {
        await this.loadGeography(question.key, 'city', undefined, this.selectedCountryIds());
      } else if (fetchedByTaxonomyType.has(question.taxonomyType)) {
        this.geographyOptions.update((g) => ({ ...g, [question.key]: fetchedByTaxonomyType.get(question.taxonomyType!)! }));
      } else {
        await this.loadGeography(question.key, question.taxonomyType);
        const options = this.geographyOptions()[question.key];
        if (options) fetchedByTaxonomyType.set(question.taxonomyType, options);
      }
    }
  }

  private async loadGeography(
    questionKey: string,
    taxonomyType: string,
    parentId?: string,
    parentIds?: string[]
  ): Promise<void> {
    this.geographyLoading.update((g) => ({ ...g, [questionKey]: true }));
    try {
      const options = await this.wizard.loadGeographyOptions(taxonomyType, parentId, parentIds);
      this.geographyOptions.update((g) => ({ ...g, [questionKey]: options }));
    } finally {
      this.geographyLoading.update((g) => ({ ...g, [questionKey]: false }));
    }
  }

  /** Mirrors AmenityPickerComponent.fetchOptions()'s combined fetch, but writes the result into
   *  the SHARED geographyOptions map (under both amenities_yes and amenities_no — either key
   *  works for optionLabel()'s lookup, they're both searching the same combined slug pool)
   *  instead of the picker's own private state — see AMENITY_SUMMARY_TAXONOMY_TYPES docblock. */
  private async loadAmenitySummaryOptions(): Promise<void> {
    const results = await Promise.all(AMENITY_SUMMARY_TAXONOMY_TYPES.map((type) => this.wizard.loadGeographyOptions(type)));
    const combined = results.flat();
    this.geographyOptions.update((g) => ({ ...g, [AMENITY_YES_KEY]: combined, [AMENITY_NO_KEY]: combined }));
  }

  /** country_region is multi-select (owner's ask, 2026-08-12) — the answer is an array of
   *  country SLUGS (see onDestinationCardSelect), resolved here to IDs via whatever
   *  geographyOptions['country_region'] already holds, for passing as suggestedGeography's
   *  parentIds. Empty when nothing's selected (region_theme step was skipped, or every country
   *  card was deselected) — the resolver already treats that as "every country". */
  private selectedCountryIds(): string[] {
    const selectedSlugs = (this.wizard.getAnswer('country_region') as string[] | undefined) ?? [];
    if (selectedSlugs.length === 0) return [];

    const countryOptions = this.geographyOptions()['country_region'] ?? [];
    return selectedSlugs
      .map((slug) => countryOptions.find((n) => n.slug === slug)?.id)
      .filter((id): id is string => !!id);
  }
}
