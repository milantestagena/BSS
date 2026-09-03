import { Injectable } from '@angular/core';
import { LocaleService } from './locale.service';

/**
 * Static UI chrome strings (buttons, gating messages, account page copy, ...) — anything NOT
 * backed by a database row (those go through TranslateDirective/X-Locale on the backend
 * instead, see graphql.service.ts). Flat key -> string per locale, English is the fallback for
 * any key missing in a non-English locale so a gap here degrades to English, never to a raw
 * key or blank text.
 *
 * Copy pass, 2026-09-03 — owner reviewed the full EN/DE table before launch (native-speaker
 * pass on both, not literal translations of each other) and sent back a set of concrete
 * rewrites: consistent informal "du" throughout German, airTemp/seaTemp spelled out
 * ("Air temperature"/"Wassertemperatur" — "Meer" alone doesn't carry the temperature meaning),
 * budgetExcludesFlightNote's leading "**" dropped (checked wizard.html first — nothing else
 * pairs a matching "*" against it, unlike requiredLegend's, so it was never a real footnote
 * reference), and generally tightening several phrases toward shorter "product app" English
 * rather than literal-feeling translation. Applied verbatim from the owner's review.
 */
const STRINGS: Record<'en' | 'de', Record<string, string>> = {
  en: {
    back: 'Back',
    proceed: 'Proceed',
    change: 'Change',
    searching: 'Searching...',
    openingBooking: 'Opening Booking.com...',
    signInWithGoogle: 'Sign in with Google',
    signOut: 'Sign out',
    credits: 'credits',
    aiCreditsRemaining: 'AI credits remaining',
    notSignedIn: "You're not signed in.",
    shareAndEarn: 'Share & earn credits',
    shareAndEarnSubtitle: 'Get +10 AI credits whenever someone you invite completes a booking with us.',
    copy: 'Copy',
    copied: 'Copied!',
    share: 'Share',
    howToGetCredits: 'How do I get more credits?',
    bookingEarnsCredits: 'A confirmed booking through us earns you +20 credits.',
    referralEarnsCredits: "Invite a friend — you'll get +10 credits when they book.",
    getYourShareLink: 'Get your share link',
    bigYesPlaceholder: 'Type and press Enter (e.g. pool, breakfast, AC)...',
    moreInfo: 'More info',
    close: 'Close',
    allInOneUnit: 'All in one unit?',
    loading: 'Loading...',
    yes: 'Yes',
    no: 'No',
    fillRequiredFields: 'Fill in the required fields above to continue.',
    citySearchPlaceholder: 'Start typing a city name...',
    children: 'Children',
    age: 'Age',
    needCrib: 'Need a crib',
    remove: 'Remove',
    addChild: '+ Add child',
    required: 'Required',
    requiredLegend: '* Required',
    numberArrayExample: 'e.g. 2, 7, 14',
    chooseDate: 'Choose a date',
    adultsPlural: 'adults',
    adultSingular: 'adult',
    childrenCount: 'children',
    childCount: 'child',
    roomsPlural: 'rooms',
    roomSingular: 'room',
    togetherOneUnit: 'Together in one unit',
    noAnswer: '—',
    destinationsGroupedIntro: 'Grouped by what matters most to you:',
    culturalTagsIntro: 'Specific requirements',
    otherOptionsHeader: 'Other options',
    bestChoicesHeader: 'Best choices',
    alsoGoodChoicesHeader: 'Also good choices',
    lessGoodChoicesHeader: 'Less good choices',
    priceLegend: 'Card color shows how much of your budget this uses: green under 70%, yellow 70–100%, red over 100%.',
    // Split 2026-09-03 (owner's ask) — the amenity/price part is a more consequential heads-up
    // than the intuitive "green is better than red" color legend, so it moved out of this info
    // popover into a visible "*" note below the city grid instead (see cityAmenityPriceHint).
    // This one stays generic enough for both the country and city steps' popovers.
    cityStepHint: "Click a card's description to see more details about the place.",
    cityAmenityPriceHint:
      "Click a city's name to see the offer. Some amenities can push the price up significantly — if the selection looks too limited, try increasing your price range or removing one.",
    search: 'Search',
    budgetExcludesFlightNote:
      "Doesn't include the flight — prices change too much from day to day for us to estimate them reliably. We'll show you flight prices once we know where you're headed.",
    budgetNoteCaveat: 'Closest match to your budget — a little more than you asked for',
    budgetNoteSelfCatering: 'Fits if you cook for yourself',
    budgetNoteMealPlan: 'Fits your chosen meal plan',
    budgetNoteRoomToSpare: 'You could even do this with a smaller budget',
    budgetNoteAllInclusiveAlsoFits: 'All-inclusive would also fit your budget',
    mealPlanNoteCaveat: "Doesn't offer exactly the meal plan you asked for",
    destinationInfoNotDescribed: 'Not described yet.',
    airTemp: 'Air temperature',
    seaTemp: 'Sea temperature',
    seeFullGuide: 'See full guide',
    guideClose: 'Close',
    guideLoading: 'Loading guide…',
    // Reworded 2026-09-03 (owner's ask) — "How to see it" read as a prescribed schedule once
    // paired with per-stop night counts; those are gone now (see destination-guide-modal.html),
    // and the title follows: what's there to experience, not how to time a visit.
    guideItineraryTitle: 'What to see',
    guideCostsTitle: 'Roughly what it costs',
    guideFromPrefix: 'From',
    guidePerPersonPerNight: 'per person / night',
    guideTipsTitle: 'Good to know',
    guidePhotosTitle: 'Photos',
    guidePhotosSubtitle: "We don't host photos ourselves — see real, current ones from around the web.",
    guideSeePhotosOnGoogle: 'See photos on Google',
    guideDepartureFrom: 'Departing from',
    guidePreviousSlide: 'Previous',
    guideNextSlide: 'Next',
    guideAccommodationLabel: 'Accommodation',
    guideFoodLabel: 'Food',
    guideEatingOutLabel: 'Eating out',
    guideSelfCateringLabel: 'Cooking for yourself',
    guidePerPersonPerDay: 'per person / day',
  },
  de: {
    back: 'Zurück',
    proceed: 'Weiter',
    change: 'Ändern',
    searching: 'Suche läuft...',
    openingBooking: 'Booking.com wird geöffnet...',
    signInWithGoogle: 'Mit Google anmelden',
    signOut: 'Abmelden',
    credits: 'Guthaben',
    aiCreditsRemaining: 'Verbleibendes KI-Guthaben',
    notSignedIn: 'Du bist nicht angemeldet.',
    shareAndEarn: 'Teilen & Guthaben verdienen',
    shareAndEarnSubtitle: 'Erhalte jedes Mal +10 KI-Guthaben, wenn jemand, den du eingeladen hast, über uns bucht.',
    copy: 'Kopieren',
    copied: 'Kopiert!',
    share: 'Teilen',
    howToGetCredits: 'Wie bekomme ich mehr Guthaben?',
    bookingEarnsCredits: 'Eine bestätigte Buchung über uns bringt dir +20 Guthaben.',
    referralEarnsCredits: 'Lade einen Freund ein – du bekommst +10 Guthaben, wenn er bucht.',
    getYourShareLink: 'Hol dir deinen Einladungslink',
    bigYesPlaceholder: 'Eingeben und Enter drücken (z. B. Pool, Frühstück, Klimaanlage) ...',
    moreInfo: 'Mehr Infos',
    close: 'Schließen',
    allInOneUnit: 'Alle in einer Unterkunft?',
    loading: 'Wird geladen...',
    yes: 'Ja',
    no: 'Nein',
    fillRequiredFields: 'Fülle die oben genannten Pflichtfelder aus, um fortzufahren.',
    citySearchPlaceholder: 'Stadtname eingeben ...',
    children: 'Kinder',
    age: 'Alter',
    needCrib: 'Babybett benötigt',
    remove: 'Entfernen',
    addChild: '+ Kind hinzufügen',
    required: 'Erforderlich',
    requiredLegend: '* Erforderlich',
    numberArrayExample: 'z. B. 2, 7, 14',
    chooseDate: 'Datum auswählen',
    adultsPlural: 'Erwachsene',
    adultSingular: 'Erwachsener',
    childrenCount: 'Kinder',
    childCount: 'Kind',
    roomsPlural: 'Zimmer',
    roomSingular: 'Zimmer',
    togetherOneUnit: 'Gemeinsam in einer Unterkunft',
    noAnswer: '—',
    destinationsGroupedIntro: 'Nach dem gruppiert, was dir am wichtigsten ist:',
    culturalTagsIntro: 'Besondere Anforderungen',
    otherOptionsHeader: 'Weitere Optionen',
    bestChoicesHeader: 'Beste Optionen',
    alsoGoodChoicesHeader: 'Auch gute Optionen',
    lessGoodChoicesHeader: 'Weniger gute Optionen',
    priceLegend: 'Die Kartenfarbe zeigt, wie viel deines Budgets benötigt wird: Grün unter 70 %, Gelb bei 70–100 %, Rot über 100 %.',
    cityStepHint: 'Klicke auf die Beschreibung einer Karte, um mehr über den Ort zu erfahren.',
    cityAmenityPriceHint:
      'Klicke auf den Namen einer Stadt, um das Angebot zu sehen. Manche Ausstattungsmerkmale können den Preis deutlich erhöhen – wenn die Auswahl zu klein ist, erhöhe deine Preisspanne oder entferne eine Anforderung.',
    search: 'Suchen',
    budgetExcludesFlightNote:
      'Ohne Flug – die Preise ändern sich von Tag zu Tag zu stark, um sie zuverlässig zu schätzen. Sobald wir dein Reiseziel kennen, zeigen wir dir die Flugpreise.',
    budgetNoteCaveat: 'Passt am besten zu deinem Budget – etwas mehr als gewünscht',
    budgetNoteSelfCatering: 'Passt, wenn du selbst kochst',
    budgetNoteMealPlan: 'Passt zu deiner gewählten Verpflegung',
    budgetNoteRoomToSpare: 'Dafür würde sogar ein kleineres Budget reichen',
    budgetNoteAllInclusiveAlsoFits: 'All-inclusive würde ebenfalls in dein Budget passen',
    mealPlanNoteCaveat: 'Bietet nicht genau die gewünschte Verpflegungsart',
    destinationInfoNotDescribed: 'Noch keine Beschreibung verfügbar.',
    airTemp: 'Lufttemperatur',
    seaTemp: 'Wassertemperatur',
    seeFullGuide: 'Vollständigen Guide ansehen',
    guideClose: 'Schließen',
    guideLoading: 'Guide wird geladen…',
    guideItineraryTitle: 'Sehenswertes',
    guideCostsTitle: 'Ungefähre Kosten',
    guideFromPrefix: 'Ab',
    guidePerPersonPerNight: 'pro Person / Nacht',
    guideTipsTitle: 'Gut zu wissen',
    guidePhotosTitle: 'Fotos',
    guidePhotosSubtitle: 'Wir hosten keine eigenen Fotos — sieh echte, aktuelle Bilder aus dem Web.',
    guideSeePhotosOnGoogle: 'Fotos bei Google ansehen',
    guideDepartureFrom: 'Abflug ab',
    guidePreviousSlide: 'Zurück',
    guideNextSlide: 'Weiter',
    guideAccommodationLabel: 'Unterkunft',
    guideFoodLabel: 'Essen',
    guideEatingOutLabel: 'Auswärts essen',
    guideSelfCateringLabel: 'Selbst kochen',
    guidePerPersonPerDay: 'pro Person / Tag',
  },
};

@Injectable({ providedIn: 'root' })
export class I18nService {
  constructor(private localeService: LocaleService) {}

  t(key: keyof (typeof STRINGS)['en'], params?: Record<string, string | number>): string {
    const locale = this.localeService.locale();
    const template = STRINGS[locale][key] ?? STRINGS.en[key] ?? key;

    if (!params) return template;

    return Object.entries(params).reduce(
      (result, [param, value]) => result.replaceAll(`{${param}}`, String(value)),
      template
    );
  }
}
