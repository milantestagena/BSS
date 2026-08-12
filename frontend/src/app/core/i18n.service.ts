import { Injectable } from '@angular/core';
import { LocaleService } from './locale.service';

/**
 * Static UI chrome strings (buttons, gating messages, account page copy, ...) — anything NOT
 * backed by a database row (those go through TranslateDirective/X-Locale on the backend
 * instead, see graphql.service.ts). Flat key -> string per locale, English is the fallback for
 * any key missing in a non-English locale so a gap here degrades to English, never to a raw
 * key or blank text.
 */
const STRINGS: Record<'en' | 'de', Record<string, string>> = {
  en: {
    back: 'Back',
    proceed: 'Proceed',
    change: 'Change',
    searching: 'Searching...',
    signInWithGoogle: 'Sign in with Google',
    signOut: 'Sign out',
    credits: 'credits',
    aiCreditsRemaining: 'AI credits remaining',
    notSignedIn: "You're not signed in.",
    shareAndEarn: 'Share & earn credits',
    shareAndEarnSubtitle: 'Get +10 AI credits every time someone you invite completes a booking with us.',
    copy: 'Copy',
    copied: 'Copied!',
    share: 'Share',
    outOfCredits: "You're out of AI credits.",
    loginForAiSearch: 'Log in to use Big NO and AI precise search.',
    howToGetCredits: 'How do I get more credits?',
    bookingEarnsCredits: 'A confirmed booking through us earns you +20 credits.',
    referralEarnsCredits: 'Or invite a friend — you get +10 credits when they book.',
    getYourShareLink: 'Get your share link',
    notRealDataTitle: 'Not Real Data — Presentation Purpose Only',
    notRealDataBody:
      'These listings are mocked up to show what the results screen will look like once connected to Booking.com.',
    hereIsWhatWeFound: "Here's what we found for you",
    honestReport: 'Honest Report',
    bigNoLogin: 'Log in to use Big NO',
    bigNoPlaceholder: "Type and hit Enter (things you'd rather avoid)...",
    bigYesPlaceholder: 'Type and hit Enter (e.g. pool, breakfast, AC)...',
    moreInfo: 'More info',
    close: 'Close',
    allInOneUnit: 'All in one unit?',
    loading: 'Loading...',
    topPick: 'Top pick',
    ratingOutOf10: '{rating}/10 rating',
    perNight: '/night',
    propertySummary: '{type} · {sqm} m² · {distance}m from the beach',
    aiReadingReviews: 'AI is reading the reviews for this one...',
    yes: 'Yes',
    no: 'No',
    fillRequiredFields: 'Fill in the required fields above to continue.',
    citySearchPlaceholder: 'Start typing a city name...',
    children: 'Children',
    age: 'Age',
    needCrib: 'Need crib',
    remove: 'Remove',
    addChild: '+ Add child',
    required: 'Required',
    requiredParenthetical: '(required)',
    numberArrayExample: 'e.g. 2, 7, 14',
    chooseDate: 'Choose a date',
    adultsPlural: 'adults',
    adultSingular: 'adult',
    childrenCount: 'children',
    childCount: 'child',
    roomsPlural: 'rooms',
    roomSingular: 'room',
    togetherOneUnit: 'Together in 1 unit',
    noAnswer: '—',
    destinationsGroupedIntro: 'Grouped by what matters most to you:',
    otherOptionsHeader: 'Other options',
    priceLegend: 'Accommodation price (relative to these options): cheaper → pricier',
  },
  de: {
    back: 'Zurück',
    proceed: 'Weiter',
    change: 'Ändern',
    searching: 'Suche läuft...',
    signInWithGoogle: 'Mit Google anmelden',
    signOut: 'Abmelden',
    credits: 'Guthaben',
    aiCreditsRemaining: 'Verbleibendes KI-Guthaben',
    notSignedIn: 'Du bist nicht angemeldet.',
    shareAndEarn: 'Teilen & Guthaben verdienen',
    shareAndEarnSubtitle: 'Erhalte +10 KI-Guthaben, wann immer jemand, den du einlädst, bei uns bucht.',
    copy: 'Kopieren',
    copied: 'Kopiert!',
    share: 'Teilen',
    outOfCredits: 'Dein KI-Guthaben ist aufgebraucht.',
    loginForAiSearch: 'Melde dich an, um Big NO und die KI-Feinsuche zu nutzen.',
    howToGetCredits: 'Wie bekomme ich mehr Guthaben?',
    bookingEarnsCredits: 'Eine bestätigte Buchung über uns bringt dir +20 Guthaben.',
    referralEarnsCredits: 'Oder lade einen Freund ein — du bekommst +10 Guthaben, wenn er/sie bucht.',
    getYourShareLink: 'Hol dir deinen Einladungslink',
    notRealDataTitle: 'Keine echten Daten — nur zu Präsentationszwecken',
    notRealDataBody:
      'Diese Angebote sind Platzhalter, um zu zeigen, wie die Ergebnisseite aussehen wird, sobald sie mit Booking.com verbunden ist.',
    hereIsWhatWeFound: 'Das haben wir für dich gefunden',
    honestReport: 'Ehrlicher Bericht',
    bigNoLogin: 'Melde dich an, um Big NO zu nutzen',
    bigNoPlaceholder: 'Tippen und Enter drücken (Dinge, die du lieber vermeiden möchtest)...',
    bigYesPlaceholder: 'Tippen und Enter drücken (z. B. Pool, Frühstück, Klimaanlage)...',
    moreInfo: 'Mehr Infos',
    close: 'Schließen',
    allInOneUnit: 'Alles in einer Unterkunft?',
    loading: 'Wird geladen...',
    topPick: 'Top-Wahl',
    ratingOutOf10: '{rating}/10 Bewertung',
    perNight: '/Nacht',
    propertySummary: '{type} · {sqm} m² · {distance}m zum Strand',
    aiReadingReviews: 'Die KI liest gerade die Bewertungen für dieses Angebot...',
    yes: 'Ja',
    no: 'Nein',
    fillRequiredFields: 'Fülle die erforderlichen Felder oben aus, um fortzufahren.',
    citySearchPlaceholder: 'Stadtname eingeben...',
    children: 'Kinder',
    age: 'Alter',
    needCrib: 'Babybett nötig',
    remove: 'Entfernen',
    addChild: '+ Kind hinzufügen',
    required: 'Erforderlich',
    requiredParenthetical: '(erforderlich)',
    numberArrayExample: 'z. B. 2, 7, 14',
    chooseDate: 'Datum wählen',
    adultsPlural: 'Erwachsene',
    adultSingular: 'Erwachsener',
    childrenCount: 'Kinder',
    childCount: 'Kind',
    roomsPlural: 'Zimmer',
    roomSingular: 'Zimmer',
    togetherOneUnit: 'Zusammen in einer Unterkunft',
    noAnswer: '—',
    destinationsGroupedIntro: 'Gruppiert nach dem, was dir am wichtigsten ist:',
    otherOptionsHeader: 'Weitere Optionen',
    priceLegend: 'Unterkunftspreis (relativ zu diesen Optionen): günstiger → teurer',
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
