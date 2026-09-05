import { Component, ElementRef, OnInit, effect, signal, ViewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute } from '@angular/router';
import { WizardService } from '../../core/wizard.service';
import { AuthService } from '../../core/auth.service';
import { I18nService } from '../../core/i18n.service';
import { AppLocale, LocaleService } from '../../core/locale.service';
import { ScrollContainerService } from '../../core/scroll-container.service';
import { TaxonomyNode, WizardQuestion, WizardStep } from '../../core/wizard.types';
import { QuestionInputComponent } from './question-input';
import { TravelersInputComponent, TravelersValue } from './travelers-input';
import { CitySearchComponent, WorldCityResult } from './city-search';
import { AmenityPickerComponent } from './amenity-picker';
import { ButtonComponent } from '../../ui/button';
import { SpinnerComponent } from '../../ui/spinner';
import { InfoPopoverComponent } from '../../ui/info-popover';
import { DestinationGuideModalComponent } from '../../ui/destination-guide-modal';

/** Meta Pixel's global `fbq` function, loaded on demand by AnalyticsService once cookie consent
 *  is given (moved out of a static index.html snippet, 2026-09-05 — see
 *  Wizard.trackPixelEvent()'s docblock for why this is read off `window` rather than declared as
 *  a bare global (ad-blockers routinely strip the whole pixel script, and referencing an
 *  undeclared bare identifier throws instead of evaluating to undefined). */
type MetaPixelFn = (...args: unknown[]) => void;

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

/** amenities_yes renders via <app-amenity-picker> (combined typeahead over
 *  tip_smestaja/accommodation_facility/room_facility) instead of a generic pill grid — see
 *  amenity-picker.ts. Owner's design, 2026-08-04. */
const AMENITY_YES_KEY = 'amenities_yes';

/** No UI at all, 2026-08-24 — the "Big NO" picker section that used to render this was removed
 *  (see AmenityPickerComponent's docblock); this key only still needs excluding from
 *  visibleQuestions below so its WizardQuestion row doesn't fall through to the generic
 *  taxonomy_multi_choice renderer with an empty option list (bug caught live: a bold label with
 *  nothing under it, "Anything you'd rather avoid?"). Same "no UI of its own" pattern as
 *  SMESTAJ_AVOID_KEY just below. */
const AMENITY_NO_KEY = 'amenities_no';

/** Same types as amenity-picker.ts's own AMENITY_TYPES — duplicated here (not read from that
 *  component) because this class needs them in the SHARED geographyOptions map for
 *  optionLabel()/stepSummary(), not just inside the picker widget's own local state. Bug
 *  fixed 2026-08-13: amenities_yes/no questions have no `taxonomyType` of their own (they span
 *  multiple types), so the generic per-step loader below always skipped them — the collapsed
 *  chat-bubble summary fell back to raw slugs ("klima" instead of the localized "Air
 *  conditioning"/"Klimaanlage"), which happened to look like real Serbian text so it read as
 *  "stuck in Serbian" even under the EN/DE toggle. Bug fixed AGAIN 2026-09-02 (owner caught it
 *  live, "kakvo bre plazanje" — raw slug for the Beach popular_activity tag): this list had
 *  drifted out of sync with AMENITY_TYPES, missing stay_type/popular_activity entirely (only
 *  ever manually kept in sync, no shared import) — any pick from either type hit the exact same
 *  fallback-to-raw-slug bug all over again. */
// tip_smestaja removed 2026-09-03 — see amenity-picker.ts's AMENITY_TYPES docblock, must stay
// in sync with it.
const AMENITY_SUMMARY_TAXONOMY_TYPES = ['accommodation_facility', 'room_facility', 'stay_type', 'popular_activity'];

/** No UI of its own — see onAmenityUnmatchedText. Exists purely so its session_field flows
 *  through persistCurrentStep like every other free_text_answers field. */
const SMESTAJ_AVOID_KEY = 'smestaj_avoid';

/** Owner's ask, 2026-08-24: hid this field's own dedicated textarea — GPT can only match typed
 *  text against our OWN known real filter catalog (see FreeTextAmenityResolver), so anything
 *  genuinely "unusual" typed here has nowhere real to go until we have real listing-level API
 *  access. Still gets populated another way, though — the amenity picker's own unmatched-text
 *  routing (onAmenityUnmatchedText) writes here too, and extractFreeTextAmenities still reads
 *  whatever lands here for catalog matches. Bring the dedicated textarea back once real API
 *  access makes "unusual" requests actually actionable. */
const SMESTAJ_PREFERENCE_KEY = 'smestaj_preference';

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
 *  reserved left column (see stepDescription()) — owner's ask, 2026-08-06, so a first-time
 *  visitor always knows why a question matters instead of just filling in blanks. Keyed by
 *  WizardStep.key, same keys used by both the generic flow and every campaign (campaigns only
 *  ever reorder/select from this same fixed step set, see WizardSeeder::seedWizardSteps()). */
const STEP_DESCRIPTIONS: Record<AppLocale, Record<string, string>> = {
  en: {
    trip_type: 'What kind of trip is this? This one choice shapes every question that follows.',
    broj_putnika: "Just headcount for now — how many of you, any kids, and what kind of group this is. We'll match destinations to this later.",
    odakle_putujes: 'Your home city, so we can give you a realistic sense of how far each suggestion actually is.',
    termin: "When are you planning to travel? We already suggest a window based on the campaign, but you can fine-tune the exact dates.",
    budzet: "Now that we know when and how many of you are going, here's a realistic starting budget — feel free to adjust it.",
    persona: "A quick read on what kind of traveler(s) you are — this steers which destinations and vibes we suggest next.",
    preferencije: "What matters most about the trip's atmosphere, plus your nightly budget — helps us narrow things down to a shortlist that actually fits.",
    zemlja_regija: "Based on everything so far, here are the countries/regions that fit best. Pick one, or tell us if none of them feel right.",
    grad: 'Now narrowing down to a specific city or resort town within that region.',
    smestaj: "Last step — the specific things that would make (or break) your stay: amenities, must-haves, deal-breakers.",
  },
  de: {
    trip_type: 'Was für eine Reise soll es werden? Diese eine Wahl bestimmt alle folgenden Fragen.',
    broj_putnika: 'Erstmal nur die Kopfzahl — wie viele seid ihr, gibt es Kinder, und was für eine Gruppe seid ihr. Passende Ziele finden wir später.',
    odakle_putujes: 'Deine Heimatstadt, damit wir dir realistisch zeigen können, wie weit jeder Vorschlag tatsächlich entfernt ist.',
    termin: 'Wann möchtest du reisen? Wir schlagen bereits einen Zeitraum basierend auf der Kampagne vor, du kannst die genauen Daten aber anpassen.',
    budzet: 'Jetzt, wo wir wissen, wann und wie viele ihr reist, hier ein realistisches Startbudget — du kannst es jederzeit anpassen.',
    persona: 'Ein kurzer Eindruck davon, was für ein Reisetyp du bist — das steuert, welche Ziele und Stimmungen wir als Nächstes vorschlagen.',
    preferencije: 'Was dir bei der Atmosphäre der Reise am wichtigsten ist, plus dein nächtliches Budget — hilft uns, eine wirklich passende Auswahl zu treffen.',
    zemlja_regija: 'Basierend auf allem bisher Gesagten sind das die am besten passenden Länder/Regionen. Wähle eins, oder sag uns, wenn keins passt.',
    grad: 'Jetzt grenzen wir es auf eine konkrete Stadt oder einen Ferienort innerhalb dieser Region ein.',
    smestaj: 'Letzter Schritt — die konkreten Dinge, die deinen Aufenthalt ausmachen (oder ruinieren): Ausstattung, Must-haves, Ausschlusskriterien.',
  },
};

/** Shown ABOVE the first step's description only, 2026-08-06 (owner's ask). Owner's ask,
 *  2026-08-24: rewritten to talk to the traveler about THEIR trip, not to a reviewer about our
 *  own product roadmap — the original version explained that "this flow is built around one
 *  campaign" and listed campaigns we're planning next, which is internal architecture a real
 *  visitor has no reason to care about. */
const CAMPAIGN_INTRO_BLURB: Record<AppLocale, string> = {
  en: 'Squeeze in one more warm-weather trip before summer ends. Answer a few quick questions, and we’ll help you find the right spot.',
  de: 'Hol dir noch eine warme Reise, bevor der Sommer endet. Beantworte ein paar kurze Fragen, und wir helfen dir, den richtigen Ort zu finden.',
};

interface ThemeIntro {
  title: string;
  subtitle: string;
  cta: string;
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
    InfoPopoverComponent,
    DestinationGuideModalComponent,
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

  readonly showCalculatingTransition = signal(false);
  readonly calculatingMessageIndex = signal(0);

  /** Owner's ask, 2026-08-25: picking a city used to visibly jump-scroll to the top of the chat
   *  right before the same-tab redirect fired (switchResultsCity's own re-render reflowing the
   *  destination-card grid under the user mid-navigation) — "nešto glupo". A blurred full-screen
   *  loading overlay instead, same idea as showCalculatingTransition: covers that reflow
   *  entirely rather than trying to prevent it, and the page is about to navigate away anyway.
   *  Reset on bfcache restore (see constructor's pageshow listener) so a Back press lands on the
   *  normal chat, not a stuck loading screen frozen in the cached snapshot. */
  readonly showCityRedirectTransition = signal(false);

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

  /** Owner's ask, 2026-09-05 (day-2-of-launch, 0/14 real visitors clicked past the old separate
   *  intro screen — "2 buttons problem": one click on the ad's own CTA, then a second one here
   *  before anything happened). A short chat-style greeting now plays immediately on load
   *  instead — the campaign's own hook (title/subtitle, formerly the intro screen's headline)
   *  plays first when present, followed by messages explaining who we are (Booking.com affiliate
   *  — trust) and why it's worth answering questions (saves hours of googling). Doubles as the
   *  original "loader while data loads" idea — real init/geography loading (see startWizard)
   *  runs concurrently with the greeting's own pacing, not as a separate fake delay;
   *  greetingDone only flips once BOTH have finished, whichever takes longer. */
  readonly visibleGreetingMessages = signal<string[]>([]);
  readonly greetingDone = signal(true);

  /** Order is deliberate (owner's ask, 2026-09-05): greeting+identity first, THEN the emotional
   *  hook, THEN the call to get started — reads as one warm conversation opener rather than a
   *  marketing headline bolted onto a disclosure. All three lines type out progressively into a
   *  SINGLE chat bubble (owner's explicit call, "spoj u 1 chat box, izuzetno je bitno") rather
   *  than one bubble per line — see the template's greeting block. Hardcoded per-locale rather
   *  than folding in themeIntro's title/subtitle dynamically — this exact wording was hand-tuned
   *  (native-speaker German pass) for the one live campaign; revisit if a second campaign with
   *  meaningfully different hook copy ever launches. */
  private readonly GREETING_MESSAGES: Record<AppLocale, string[]> = {
    en: [
      "Hi! 👋 We're a Booking.com affiliate partner — and we'll save you hours of googling.",
      "Haven't been to the sea lately? It's still beach weather on the Mediterranean — don't miss it!",
      "Let's find your perfect place, fast — just answer a few quick questions to get started.",
    ],
    de: [
      'Hallo! 👋 Wir sind Booking.com-Affiliate-Partner – und ersparen dir stundenlanges Googeln.',
      'Lange nicht mehr am Meer gewesen? Am Mittelmeer ist noch Strandwetter – verpass es nicht!',
      'Lass uns schnell die passende Unterkunft für dich finden – beantworte dafür einfach ein paar kurze Fragen.',
    ],
  };

  private async playGreeting(): Promise<void> {
    this.visibleGreetingMessages.set([]);
    this.greetingDone.set(false);

    for (const message of this.GREETING_MESSAGES[this.locale.locale()]) {
      await new Promise((resolve) => setTimeout(resolve, 700));
      this.visibleGreetingMessages.update((messages) => [...messages, message]);
    }
    // A beat to actually read the last message before the real form appears underneath it.
    await new Promise((resolve) => setTimeout(resolve, 900));
    this.greetingDone.set(true);
  }

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
    private route: ActivatedRoute,
    private scrollContainer: ScrollContainerService
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

    // Owner's ask, 2026-08-25 — see showCityRedirectTransition's docblock. A same-tab redirect
    // to Booking leaves this overlay showing right up until the browser actually navigates away;
    // if the traveler then presses Back, the browser restores this EXACT page from bfcache
    // (`event.persisted === true`) — including whatever signal state was frozen at that moment.
    // Without this, Back would land on a loading screen stuck open forever, never told to hide
    // itself since no Angular code runs again on a bfcache restore.
    window.addEventListener('pageshow', (event: PageTransitionEvent) => {
      if (event.persisted) this.showCityRedirectTransition.set(false);
    });
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

    // No more separate click-gated intro screen — see startWizard/playGreeting's docblocks
    // ("2 buttons problem", 2026-09-05). Both campaign and generic entry points start the same
    // way now; the campaign's own hook plays as the first greeting bubbles instead.
    await this.startWizard();
  }

  /** The real, affiliate-tracked accommodation search — owner's ask, 2026-08-23: the old mock
   *  hotel-card results screen was never actually linked to a real Booking.com search anywhere,
   *  so no visitor could ever complete a real, trackable booking through the site. Now the
   *  destination for selectResultsCity()'s redirect, straight off the 'grad' step — see
   *  onDestinationCardSelect. */
  get bookingUrl(): string | null {
    return (this.wizard.compiledQuery()?.['bookingUrl'] as string | undefined) ?? null;
  }

  /** Locally-selected city, set by selectResultsCity() right before it redirects — not a UI
   *  selection state to render anywhere anymore (the old results-screen city-switcher pills this
   *  was built for are gone, 2026-08-24), just how searchResultsCity() knows which city to
   *  persist/redirect for. */
  readonly selectedResultsCityId = signal<string | null>(null);

  /** Which destination's deep-dive guide modal is open, if any — see wizard.html's single
   *  reusable app-destination-guide-modal instance and DestinationGuideModalComponent. Null
   *  keeps the dialog closed; set by a card's "see full guide" click, nulled again when the
   *  modal's own (closed) event fires (Esc/backdrop/close button all route through that). */
  readonly guideModalNode = signal<TaxonomyNode | null>(null);

  /** Same compiledQuery read as prefillRecommendedDates — real checkin/checkout for the guide
   *  modal's cover slide, once resolvable. */
  get guideCheckin(): string | null {
    return (this.wizard.compiledQuery()?.['bookingParams'] as { checkin?: string } | undefined)?.checkin ?? null;
  }

  get guideCheckout(): string | null {
    return (this.wizard.compiledQuery()?.['bookingParams'] as { checkout?: string } | undefined)?.checkout ?? null;
  }

  /** Owner's call, 2026-08-14: clicking a shortlisted-city pill is the whole decision — no
   *  separate Search button anymore, see wizard.html. Owner's ask, 2026-08-24: the click should
   *  go straight to Booking, not stop at an intermediate "here's what we found" screen requiring
   *  a second click — see searchResultsCity's own docblock for why the tab opens here,
   *  synchronously, rather than after the awaited switch below.
   *
   *  Same tab, final answer, 2026-08-24 (owner's third call same day) — tried new-tab twice
   *  (window.open blank-then-redirect), confirmed live BOTH times it can fail silently on real
   *  browsers (a blank/broken tab, not a working redirect) — no purely-JS way to guarantee a
   *  script-initiated popup survives every ad/privacy-blocker out there. Same-tab location
   *  change is never blocked (it isn't a popup at all); the browser's Back button returns here
   *  via normal bfcache.
   *
   *  `lockedScrollY` (2026-08-25, owner caught the scroll-jump live twice): captured by the
   *  caller BEFORE onAnswerChange runs (see onDestinationCardSelect) — marking the card selected
   *  (checkmark badge, border change) can itself reflow the grid, so capturing scrollY any later
   *  than this already missed it. See searchResultsCity's docblock for the rest. */
  selectResultsCity(node: TaxonomyNode, lockedScrollY: number): void {
    this.trackPixelEvent('InitiateCheckout', { content_name: node.label });
    // Owner's ask, 2026-09-05 ("dokle je stigo pa odustao") — the funnel's final step, same
    // WizardEvent log step_viewed already writes to, so BookingFunnelReport can show a real
    // drop-off curve (every step_viewed reached vs. how many actually got redirected), not just
    // a raw page-visit count.
    void this.wizard.recordEvent('booking_redirect', { destination: node.label });
    this.selectedResultsCityId.set(node.id);
    void this.searchResultsCity(lockedScrollY);
  }

  /** Fires a Meta Pixel event — this is the real conversion signal for the FB/IG ad campaign
   *  (the actual booking happens off-site on Booking.com, so "clicked through to book" is the
   *  closest thing we have, see CLAUDE.md §7/marketing status). Best-effort only: reads `fbq` off
   *  `window` rather than a bare global reference, and swallows any error — an ad-blocker or
   *  privacy extension blocking the Pixel script must never break the real booking redirect this
   *  fires alongside (see selectResultsCity). 2026-09-04, owner's own Business Manager Pixel. */
  private trackPixelEvent(eventName: string, params?: Record<string, unknown>): void {
    try {
      const fbq = (window as unknown as { fbq?: MetaPixelFn }).fbq;
      fbq?.('track', eventName, params);
    } catch {
      // Tracking is best-effort — never let it interfere with the actual user action.
    }
  }

  /** Re-runs the results screen against a different shortlisted city — same session, no wizard
   *  steps re-walked. See selectResultsCity's docblock for the tab-handling story.
   *
   *  Bug fixed 2026-08-25 (owner caught it live, twice) — first fix (only clearing the overlay
   *  when not navigating away) helped but didn't fully stop it: switchResultsCity's own
   *  setAnswer('city', ...)/re-render still reflows the destination-card grid and shifts the
   *  document's real scroll position, and a `position: fixed` overlay covering the VIEWPORT
   *  doesn't stop the VIEWPORT itself from scrolling under it. Rather than keep hunting for the
   *  exact reflow, this pins scrollY to wherever it actually was at the moment of the click
   *  (passed in from onDestinationCardSelect, captured before anything else ran) and forces it
   *  back on every 'scroll' event for the whole operation — a guarantee that holds regardless of
   *  what causes the reflow, not a fix aimed at one specific cause.
   *
   *  Targets ScrollContainerService's element, not `window`, since 2026-09-05 — the page itself
   *  no longer scrolls (see app.html's "stavi skroler na nivo chata" layout), the nested
   *  #scrollContainer div does. */
  async searchResultsCity(lockedScrollY: number = this.scrollContainer.container()?.scrollTop ?? 0): Promise<void> {
    const cityId = this.selectedResultsCityId();
    if (!cityId) return;

    const container = this.scrollContainer.container();
    const holdScroll = (): void => container?.scrollTo(0, lockedScrollY);
    container?.addEventListener('scroll', holdScroll);

    this.wizard.loading.set(true);
    this.showCityRedirectTransition.set(true);
    let navigatingAway = false;
    try {
      await this.wizard.switchResultsCity(cityId);
      if (this.bookingUrl) {
        navigatingAway = true;
        window.location.href = this.bookingUrl;
      }
    } finally {
      container?.removeEventListener('scroll', holdScroll);
      // `finally` always runs, even right after triggering the navigation above —
      // window.location.href doesn't tear this page down synchronously, the browser keeps
      // rendering it for a beat while Booking.com's response comes in. Clearing the overlay
      // unconditionally here would re-reveal the page for that whole gap, right before the real
      // page swap — only cleared when NOT navigating away; a bfcache restore (pageshow listener
      // in the constructor) covers the "stuck forever" risk on the redirect path instead.
      this.wizard.loading.set(false);
      if (!navigatingAway) {
        this.showCityRedirectTransition.set(false);
      }
    }
  }

  private async startWizard(): Promise<void> {
    this.budgetManuallyEdited = false;
    // Greeting plays concurrently with real data loading below, not as a separate fake delay —
    // see playGreeting's docblock. greetingDone only flips once the greeting's own pacing AND
    // this whole init sequence have both finished.
    const greetingPromise = this.playGreeting();
    await this.wizard.init(this.campaignKey ?? undefined);
    // Fire-and-forget — geo-IP lookup latency must never delay the visible wizard, see
    // WizardService.detectHomeCity docblock.
    void this.wizard.detectHomeCity();
    const firstStepKey = this.wizard.currentStep()?.key;
    if (firstStepKey) void this.wizard.recordEvent('step_viewed', { stepKey: firstStepKey });
    await this.loadGeographyForCurrentStep();
    this.prefillRecommendedDates();
    this.prefillDefaultAdultsCount();
    this.prefillAccommodationTypePreference();
    this.syncDefaultBudget();
    await greetingPromise;
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
        q.key !== SMESTAJ_AVOID_KEY &&
        q.key !== SMESTAJ_PREFERENCE_KEY
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

  /** Typed amenity text that matched nothing in the taxonomy — never silently lost, routed to
   *  smestaj_preference (the wishlist free-text field, same one extractFreeTextAmenities reads
   *  — see FreeTextAmenityResolver). */
  onAmenityUnmatchedText(text: string): void {
    const existing = (this.wizard.getAnswer('smestaj_preference') as string) ?? '';
    this.wizard.setAnswer('smestaj_preference', existing ? `${existing}\n${text}` : text);
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
    // exactly 4 or 5 gets the "stay together?" yes/no on this same step instead (see
    // showRoomsTogetherQuestion / onRoomsTogetherChoice) — don't overwrite an answer they may
    // have already given there if they tweak the headcount afterward and it's still 4-5. A
    // group of 6+ always splits across rooms — no real per-apartment pricing data past 5 in one
    // unit (see WizardCampaignDestinationPrice::roomMultiplierSumFor) — so it's silently
    // defaulted too, same as ≤3, just to a computed room count instead of 1. This also clears
    // any stale "1" left over from a 4/5 "together" answer if the headcount is bumped past 5.
    const total = (value.adultsCount ?? 0) + value.childrenAges.length;
    if (total > 0 && total <= 3) {
      this.wizard.setAnswer('number_of_rooms', 1);
    } else if (total > 5) {
      this.wizard.setAnswer('number_of_rooms', Math.ceil(total / 3));
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

  /** True only for a group of exactly 4 or 5 — ≤3 and 6+ are both silently defaulted in
   *  onTravelersChange, never asked at all. Narrowed from ">3" to "4 or 5" specifically,
   *  2026-08-31: real per-apartment pricing (WizardCampaignDestinationPrice::
   *  roomMultiplierSumFor) only has a captured "everyone in one unit" rate up to 5 people —
   *  past that there's no real single-apartment price to offer, so 6+ always splits with no
   *  question. See ROOMS_QUESTION_KEY. */
  get showRoomsTogetherQuestion(): boolean {
    const step = this.wizard.currentStep();
    const total = this.wizard.totalTravelers();
    return !!step?.questions.some((q) => q.key === ROOMS_QUESTION_KEY) && (total === 4 || total === 5);
  }

  /** Owner's call, 2026-08-14: the `grad` step is exactly-one-city-by-definition, and
   *  onDestinationCardSelect already advances the instant a card is clicked — same "no separate
   *  Proceed button" pattern as showRoomsTogetherQuestion above, just driven by step key since
   *  there's no yes/no choice here to swap the nav row for. */
  get isCityStep(): boolean {
    return this.wizard.currentStep()?.key === 'grad';
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

  /** Owner's ask, 2026-08-14: "dodaj one komentare... ovde mozes i sa manjim budzetom" — turn
   *  the backend's budgetFit/budgetCaveat/budgetFitPercent signals into a short, honest reason
   *  instead of a bare price color. budgetFit/budgetFitPercent are now computed for BOTH
   *  type=country and type=city cards (2026-09-01 — see GeographyResolver::filterByBudget).
   *  Returns null when there's nothing worth saying (a plain, unremarkable fit) rather than
   *  forcing a caption onto every single card. mealPlanCaveat (2026-08-31) is checked first and
   *  independently of the budget parts below (see GeographyResolver::mealPlanFitFor). */
  budgetNoteFor(node: TaxonomyNode): string | null {
    const parts: string[] = [];

    if (node.mealPlanCaveat) {
      parts.push(this.i18n.t('mealPlanNoteCaveat'));
    }

    if (node.budgetCaveat) {
      parts.push(this.i18n.t('budgetNoteCaveat'));
    } else if (node.budgetFit === 'self_catering' && this.wizard.getAnswer('meal_style') !== 'sam_se_snalazim') {
      // Bug fixed 2026-09-03 (owner caught it live): this used to show unconditionally whenever
      // budgetFit was 'self_catering' — including when the traveler had ALREADY answered
      // meal_style=sam_se_snalazim themselves, where "fits if you cook for yourself" just
      // restates their own pick back at them, useless. The genuinely useful case is the
      // OPPOSITE: someone who picked restaurants (or hasn't answered yet) sees a destination
      // that's otherwise a bit pricier, with a heads-up that switching to self-catering (buy
      // your own ham/cheese/bread instead of eating out) would make it work — a real alternative
      // path, not a restated answer.
      parts.push(this.i18n.t('budgetNoteSelfCatering'));
    } else if (node.budgetFit && node.budgetFit !== 'eating_out') {
      parts.push(this.i18n.t('budgetNoteMealPlan'));
    } else if (node.budgetFit === 'eating_out' && (node.budgetFitPercent ?? 100) < 70) {
      // <70% of total_budget actually consumed (owner-confirmed threshold, 2026-09-01) is the
      // "you have real headroom" signal — replaces the old relative priceRank<=2 check, which
      // could read "room to spare" on a destination that was never actually compared against
      // the traveler's real budget at all.
      parts.push(this.i18n.t('budgetNoteRoomToSpare'));
    }

    // "All-inclusive also fits" cross-check removed, 2026-09-02 (owner's call, same session as
    // dropping meal_plan_preference/the mealplan= URL filter) — we have no real all-inclusive
    // price data to back this claim, only a country-level hospitality-meta ESTIMATE
    // (BudgetEstimationEngine::allInclusiveFits), the exact kind of guess the day's live
    // research showed can be wildly wrong for board-plan pricing specifically (2x-12x swings
    // between destinations). node.allInclusiveFits is still populated server-side but
    // deliberately unused here now.

    return parts.length > 0 ? parts.join(' · ') : null;
  }

  /** 2-letter code for the City-step country badge (see wizard.html) — falls back to the full
   *  label for any country missing WizardSeeder's iso_code meta, so a gap here degrades to the
   *  old (verbose but correct) behavior rather than showing blank. */
  countryCodeFor(parent: { label: string; meta?: Record<string, unknown> | null }): string {
    return (parent.meta?.['iso_code'] as string | undefined) ?? parent.label;
  }

  /** Owner's ask, 2026-08-24: the City-step badge moves from a corner text label to a tiny flag
   *  sitting right after the city name. First tried as a Unicode flag emoji (two "regional
   *  indicator" characters) — reverted the same day when it showed as plain "CY"/"GR" text on
   *  Windows/Chrome: Windows' bundled emoji font has historically excluded most country flags,
   *  so the browser falls back to rendering the raw regional-indicator letters instead of
   *  ligature-ing them into an actual flag glyph — not fixable from CSS/JS, a real font gap. A
   *  real flag IMAGE (flagcdn.com, a free public flag-image service, no API key) renders
   *  identically everywhere regardless of the OS font. Null for anything without a valid
   *  2-letter iso_code — no image to build, template falls back to countryCodeFor()'s plain text. */
  countryFlagUrlFor(parent: { label: string; meta?: Record<string, unknown> | null }): string | null {
    const code = (parent.meta?.['iso_code'] as string | undefined) ?? '';
    if (!/^[A-Za-z]{2}$/.test(code)) return null;

    return `https://flagcdn.com/24x18/${code.toLowerCase()}.png`;
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
    const byPrice = (a: TaxonomyNode, b: TaxonomyNode) =>
      (a.budgetFitPercent ?? Number.MAX_SAFE_INTEGER) - (b.budgetFitPercent ?? Number.MAX_SAFE_INTEGER);

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

  /** "Superstar" badge — owner's ask, 2026-08-17: a soft alternative to a hard AND/OR toggle for
   *  preference_tags (a real AND would too often collide with this campaign's sparse per-city
   *  tag coverage and return nothing). Backend-computed (see GeographyResolver::isPerfectMatch)
   *  since it needs the SAME explicit+implied tag total the resolver already uses for matching,
   *  and — for type=country — has to check child cities too (a country only qualifies if at
   *  least one of its own cities would also qualify, so the star is never a promise the City
   *  step can't actually keep; owner caught this live, 2026-08-17, on Turkey). */
  isPerfectMatch(node: TaxonomyNode): boolean {
    return !!node.perfectMatch;
  }

  /** True if ANY card for this question has a budgetFitPercent — the legend line renders ONCE
   *  near the top of the whole destination list, not per match-tier group (owner's catch,
   *  2026-09-02: repeating the same line under every group header read as noisy — "ova poruka
   *  nema potrebe da se ponavlja"). Originally rendered per-group (2026-08-12 ask), moved back
   *  to a single top-level line the same session once real budgetFitPercent data made every
   *  group actually carry it, unlike the sparser priceRank days this was first written for. */
  anyGroupHasBudgetFitData(question: WizardQuestion): boolean {
    return this.groupedDestinations(question).some((group) => group.nodes.some((n) => n.budgetFitPercent != null));
  }

  /** Absolute %-of-budget coloring for a destination card — owner-confirmed thresholds,
   *  2026-09-01: <70% green (comfortable room to spare), 70-100% yellow, >100% red. Replaces the
   *  old 5-tier priceRank (purely relative to whatever else was on screen, never compared
   *  against the traveler's actual stated budget — a destination could show green while
   *  genuinely unaffordable, caught live on Antalya). Empty string (no coloring) when
   *  budgetFitPercent is null — total_budget or a real price total isn't known yet. */
  budgetFitClass(node: TaxonomyNode): string {
    // Thinned from 10px and tier 3 shifted off amber-500, 2026-08-21 (design pass) — that
    // exact color/weight combo read as "this card IS the primary action", the same visual
    // language as the amber-500 Proceed/CTA button elsewhere in this app. 4px is enough to
    // read as an accent, not a warning label.
    const percent = node.budgetFitPercent;
    if (percent == null) return '';

    if (percent < 70) return 'border-l-4 border-l-emerald-500';
    if (percent <= 100) return 'border-l-4 border-l-yellow-500';
    return 'border-l-4 border-l-red-500';
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
    // Bug fixed 2026-09-03 (owner caught it live: un-picked Chillseeker on the persona step —
    // now possible thanks to the toggle-off fix above — but "Peaceful & quiet" stayed locked on
    // the already-loaded preference_tags step). persona/persona_group implies/excludes onto
    // preference_tag (see WizardSeeder's persona<->preference_tag relations), computed
    // server-side from the session's CURRENT selected nodes — but preference_tags' options were
    // already fetched and cached in geographyOptions from an earlier visit, so nothing re-read
    // that computation just because an earlier answer changed underneath it. Same targeted
    // re-fetch pattern as region_theme/country_region above, not the broader (and much more
    // expensive) loadGeographyForAllVisitedSteps used for a locale switch.
    if (question.key === 'persona' || question.key === 'persona_group') {
      void this.loadGeography('preference_tags', 'preference_tag');
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
      // Owner's ask, 2026-08-24: picking a city on the 'grad' step (the last one) now redirects
      // straight to Booking instead of calling goNext() — see onDestinationCardSelect — so
      // currentStepIndex can no longer reach steps.length here. The old "finished" results
      // screen this used to gate (shortlisted-city pills, flight link, back-to-session) is gone
      // along with it, see wizard.html.
      const newStepKey = this.wizard.currentStep()?.key;
      if (newStepKey) void this.wizard.recordEvent('step_viewed', { stepKey: newStepKey });
      // "Screen 1" -> "screen 2" boundary: all Q&A (smestaj is the last screen-1 step) just
      // finished, zemlja_regija (destination cards) is next. Owner's call, 2026-08-04: a
      // "calculating" transition here, not an instant swap — "zarolamo neki loader koji kao
      // nesto mnogo racuna". Only on the FORWARD crossing (prevStepKey check), not every time
      // this step happens to be current (e.g. re-editing something earlier and re-advancing).
      if (newStepKey === 'zemlja_regija' && prevStepKey === 'smestaj') {
        // Owner's ask, 2026-08-24 — see FreeTextAmenityResolver's docblock. Fire-and-forget:
        // doesn't feed destination narrowing (amenities never affect GeographyResolver), only
        // the eventual real Booking link, so it must never delay this transition.
        void this.wizard.extractFreeTextAmenities();
        await this.runCalculatingTransition();
      } else {
        await this.loadGeographyForCurrentStep();
        this.prefillRecommendedDates();
        this.prefillDefaultAdultsCount();
        this.prefillAccommodationTypePreference();
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
   *
   * Owner's call, 2026-08-14: picking a city is exactly-one by definition, so there's nothing
   * left to decide once a card is clicked — the separate Proceed button on the `grad` step was
   * pure friction. Clicking a city card now answers AND advances in one tap; country_region
   * stays a plain toggle since it's multi-select (still needs its own Proceed).
   *
   * Owner's ask, 2026-08-24: "advances" now means straight to Booking, not to the next wizard
   * step — picking a city here reuses the exact same selectResultsCity() the results screen's
   * own shortlisted-city pills use (new tab, real bookingUrl once the city is persisted), instead
   * of goNext() walking into the results screen first. Confirmed live: the redirect itself takes
   * a beat (switchResultsCity's own network round-trip) before the tab lands anywhere — not a
   * bug, just the real request time.
   */
  onDestinationCardSelect(question: WizardQuestion, node: TaxonomyNode): void {
    if (question.key === 'country_region') {
      const current = (this.wizard.getAnswer('country_region') as string[] | undefined) ?? [];
      const next = current.includes(node.slug) ? current.filter((s) => s !== node.slug) : [...current, node.slug];
      this.onAnswerChange(question, next);
      return;
    }

    if (question.key === 'city') {
      // Captured BEFORE onAnswerChange below, 2026-08-25 (owner caught the scroll-jump live,
      // twice) — marking the card selected (checkmark badge appearing, border changing) can
      // itself reflow the grid, so locking scrollY any later than this already missed it. See
      // selectResultsCity's docblock for the rest of the story.
      this.selectResultsCity(node, this.scrollContainer.container()?.scrollTop ?? 0);
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

  /** The general-knowledge vibe/atmosphere writeup seeded onto the node — see
   *  WizardSeeder::seedCityAndCountryVibeProfiles(), 2026-08-04. Reads the dedicated, translated
   *  `vibeDescription` GraphQL field (2026-09-03 fix — this used to dig `meta.vibe_profile.
   *  description` out client-side, which could never be locale-aware since @translate only ever
   *  sees one resolved scalar field, not a path inside the raw meta JSON). Null if this
   *  particular node hasn't been written up yet (not every taxonomy node has one). */
  vibeDescription(node: TaxonomyNode | null): string | null {
    return node?.vibeDescription ?? null;
  }

  /** Owner's ask, 2026-08-24: "24°C" for a single-month trip, "22–26°C" once min/max genuinely
   *  differ (a trip spanning two calendar months) — see GeographyResolver::climateSummaryFor. */
  temperatureLabel(range: { min: number; max: number } | null | undefined): string | null {
    if (!range) return null;
    const min = Math.round(range.min);
    const max = Math.round(range.max);
    return min === max ? `${min}°C` : `${min}–${max}°C`;
  }

  /** Owner's ask, 2026-08-24: the destination card's 2-line description preview used CSS
   *  line-clamp, which cuts wherever the pixel boundary happens to land — sometimes right after
   *  a "—" used as a structural pause inside a real vibe_profile description ("enormously —…"),
   *  reading oddly with nothing between the dash and the ellipsis. Character-count truncation
   *  instead, trimming trailing punctuation/whitespace (anything that isn't a letter, digit, or
   *  quote mark) before adding the ellipsis, so it always ends on an actual word or a closing
   *  quote. maxLength is a plain estimate for "about 2 lines" at this card's width/font-size,
   *  not a pixel-exact measurement — first guess (90) rendered as ~5 lines on the narrower
   *  2-column mobile card width (owner caught it live: "90 je 5 reda") — ~18 characters/line at
   *  that width, not the ~45 assumed. 40 now; adjust again if it visibly over/under-fills. */
  truncatedVibeDescription(node: TaxonomyNode): string | null {
    const full = this.vibeDescription(node);
    if (!full) return null;

    const maxLength = 40;
    if (full.length <= maxLength) return full;

    const trimmed = full.slice(0, maxLength).replace(/[^a-zA-Z0-9"'‘’“”)]+$/, '');
    return `${trimmed}…`;
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

  /** Owner's ask, 2026-09-02: the first live UI for `tip_smestaja` (Hotel/Apartment/Villa/
   *  Holiday home/Guest house/Chalet) defaults to every option SELECTED, opt-out rather than
   *  opt-in — "sto manje koraka manje odustajanja," most travelers don't care about property
   *  type specifically, so forcing a pick would be pure friction; the minority with a real
   *  preference just unchecks what they don't want. Same "prefill, never overwrite a real pick"
   *  rule as prefillRecommendedDates/prefillDefaultAdultsCount — only fires once, before the
   *  traveler has touched this question at all. Depends on loadGeographyForCurrentStep() having
   *  already populated geographyOptions for this step (same ordering as the other two prefills). */
  private prefillAccommodationTypePreference(): void {
    const step = this.wizard.currentStep();
    if (!step?.questions.some((q) => q.key === 'accommodation_type_preference')) return;
    if (this.wizard.getAnswer('accommodation_type_preference') != null) return;

    const options = this.geographyOptions()['accommodation_type_preference'];
    if (!options?.length) return;

    this.wizard.setAnswer(
      'accommodation_type_preference',
      options.map((o) => o.slug)
    );
  }

  /** Trip length in days, best-effort across whichever source is actually available yet: a real
   *  picked date_range, then termin_category's own default_duration_days meta (generic flow,
   *  where termin_category is a real loaded question), then the campaign's preset trip length
   *  (campaign flow, where termin_category is preset and never rendered — see
   *  WizardCampaign::presetTripLengthDays()). Null if none of the three are available yet. */
  private tripLengthDays(): number | null {
    const range = this.wizard.getAnswer('date_range') as [string, string] | undefined;
    if (range?.[0] && range?.[1]) {
      const days = Math.round((Date.parse(range[1]) - Date.parse(range[0])) / 86400000);
      if (days > 0) return days;
    }

    const terminId = this.wizard.getAnswer('termin_category') as string | undefined;
    const node = terminId ? this.geographyOptions()['termin_category']?.find((n) => n.id === terminId) : undefined;
    const days = node?.meta?.['default_duration_days'] as number | undefined;
    if (typeof days === 'number') return days;

    return this.wizard.presetTripLengthDays() ?? null;
  }

  /** Owner's ask, 2026-09-01: a realistic total_budget default anchored to real spending data
   *  (Stiftung für Zukunftsfragen 2023 German Tourism Analysis: ~€129/day/person all-in) instead
   *  of the old flat per-head meta rate, which ignored trip length entirely and double-counted
   *  accommodation for solo travelers (a single person pays the same apartment price as a
   *  couple, so their per-person burden is higher, not lower — hence the 1.6x solo multiplier
   *  below rather than a flat 1x). €100/adult/day (owner's downward revision, 2026-09-01, same
   *  day as the original €125 pick — the €129 all-in Stiftung figure likely already bundles
   *  travel/flight costs this app's accommodation+food-only budget shouldn't inherit, and the
   *  live default read as too generous), €75/child/day, × trip length, rounded UP to the nearest
   *  €50 (same "never let the default undersell reality" principle as the post-Rhodes
   *  screenshot-margin convention — see kampanje.md/CLAUDE.md §8). Replaces the old
   *  WizardCampaign.meta.default_budget_per_adult_eur/default_budget_per_child_eur mechanism
   *  entirely — the formula is now global, not per-campaign-configurable. Stops touching the
   *  field entirely once budgetManuallyEdited is set (the user typed a value or used the +/-
   *  stepper themselves) — "ako je rucno nesto menjao, vise ne prihvataj promene". No-ops if
   *  total_budget isn't a question on the current step, or trip length isn't known yet. */
  private syncDefaultBudget(): void {
    if (this.budgetManuallyEdited) return;

    const step = this.wizard.currentStep();
    if (!step?.questions.some((q) => q.key === 'total_budget')) return;

    const adults = (this.wizard.getAnswer('adults_count') as number) ?? 0;
    const children = ((this.wizard.getAnswer('children_ages') as number[]) ?? []).length;
    if (adults === 0 && children === 0) return;

    const days = this.tripLengthDays();
    if (days == null) return;

    let daily = adults * 100 + children * 75;
    if (adults === 1 && children === 0) daily *= 1.6;

    this.wizard.setAnswer('total_budget', Math.ceil((daily * days) / 50) * 50);
  }

  goBack(): void {
    this.wizard.goBack();
    this.scrollToActiveStep();
  }

  /**
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
   *  reads as a scroll.
   *
   *  Targets ScrollContainerService's element, not `window`, since 2026-09-05 — see
   *  searchResultsCity's matching note. getBoundingClientRect().top is always viewport-relative
   *  regardless of which element actually scrolls, so adding the CONTAINER's own scrollTop (not
   *  window.scrollY) still correctly converts it into that container's scroll-space. */
  private scrollToActiveStep(): void {
    const HISTORY_PEEK_PX = 96;
    setTimeout(() => {
      const el = this.activeStepAnchor?.nativeElement;
      const container = this.scrollContainer.container();
      if (!el || !container) return;
      const top = el.getBoundingClientRect().top + container.scrollTop - HISTORY_PEEK_PX;
      container.scrollTo({ top: Math.max(top, 0), behavior: 'smooth' });
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
      // Bug fixed 2026-08-24 (owner caught it live: skipping the amenities step entirely left an
      // EMPTY, degenerate chat bubble instead of the usual "—" no-answer text). An empty array
      // (amenities_yes with nothing picked) isn't undefined/null/'', so it slipped past this
      // check, got formatted into an empty string by formatAnswer's Array.join, and still
      // counted toward parts.length > 0 below — the bubble ended up with real content that was
      // just... nothing.
      if (value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0)) continue;

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
    // Bug fixed 2026-08-21 (owner caught it live, design pass): this used to fall through to
    // the generic Array.isArray join below, showing the raw ISO pair ("2026-09-19, 2026-09-27")
    // verbatim in the chat bubble instead of a formatted range.
    if (question.inputType === 'date_range' && Array.isArray(value)) {
      return this.formatDateRange(value as string[]);
    }
    if (Array.isArray(value)) return value.join(', ');
    // Owner's ask, 2026-08-11: the chat-bubble summary showed a bare number ("800") for the
    // budget question — needs the currency unit, all amounts in this app are EUR.
    if (question.key === 'total_budget') return `${value} EUR`;
    return String(value);
  }

  /** [fromIso, toIso] -> "Sep 19 – Sep 27, 2026" (en) / "19. Sep. – 27. Sep. 2026" (de) — locale-
   *  aware via Intl.DateTimeFormat, matching every other locale-driven bit of copy here rather
   *  than hardcoding one format. Empty/partial pairs fall back to whatever raw parts exist. */
  private formatDateRange(value: string[]): string {
    const [from, to] = value;
    if (!from && !to) return '';

    const bcp47: Record<AppLocale, string> = { en: 'en-GB', de: 'de-DE' };
    const formatter = new Intl.DateTimeFormat(bcp47[this.locale.locale()], { day: 'numeric', month: 'short', year: 'numeric' });

    const fromDate = from ? new Date(from) : null;
    const toDate = to ? new Date(to) : null;
    if (fromDate && toDate) return `${formatter.format(fromDate)} – ${formatter.format(toDate)}`;

    return formatter.format((fromDate ?? toDate)!);
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
    if (step.questions.some((q) => q.key === AMENITY_YES_KEY)) {
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
   *  the SHARED geographyOptions map (under amenities_yes, for optionLabel()'s lookup) instead
   *  of the picker's own private state — see AMENITY_SUMMARY_TAXONOMY_TYPES docblock. */
  private async loadAmenitySummaryOptions(): Promise<void> {
    const results = await Promise.all(AMENITY_SUMMARY_TAXONOMY_TYPES.map((type) => this.wizard.loadGeographyOptions(type)));
    const combined = results.flat();
    this.geographyOptions.update((g) => ({ ...g, [AMENITY_YES_KEY]: combined }));
  }

  /** country_region is multi-select (owner's ask, 2026-08-12) — the answer is an array of
   *  country SLUGS (see onDestinationCardSelect), resolved here to IDs via whatever
   *  geographyOptions['country_region'] already holds, for passing as suggestedGeography's
   *  parentIds.
   *
   *  Bug fixed 2026-08-14: nothing selected used to resolve to `[]`, which the backend reads as
   *  "no parent filter at all" — that queried cities from EVERY country in the DB, not just the
   *  ones actually offered on this narrowed screen (owner caught it live: Bruges/Belgium showing
   *  up in a Mediterranean summer-sea campaign). Owner's call: an untouched country step means
   *  "every OFFERED country stays in", so falls back to every id currently in
   *  geographyOptions['country_region'] (the already budget/cultural/climate-narrowed candidate
   *  set) instead of an empty array. */
  private selectedCountryIds(): string[] {
    const countryOptions = this.geographyOptions()['country_region'] ?? [];
    const selectedSlugs = (this.wizard.getAnswer('country_region') as string[] | undefined) ?? [];
    const slugs = selectedSlugs.length > 0 ? selectedSlugs : countryOptions.map((n) => n.slug);

    return slugs
      .map((slug) => countryOptions.find((n) => n.slug === slug)?.id)
      .filter((id): id is string => !!id);
  }
}
