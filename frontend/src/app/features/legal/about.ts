import { Component, computed, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { GraphqlService } from '../../core/graphql.service';
import { LocaleService } from '../../core/locale.service';
import { ButtonComponent } from '../../ui/button';

const SUBSCRIBE_MUTATION = `
  mutation SubscribeToNewsletter($email: String!) {
    subscribeToNewsletter(email: $email)
  }
`;

interface AboutCopy {
  back: string;
  title: string;
  paragraphs: string[];
  subscribeHeading: string;
  subscribeSubtitle: string;
  emailPlaceholder: string;
  subscribeButton: string;
  submittedMessage: string;
  errorMessage: string;
}

const COPY: Record<'en' | 'de', AboutCopy> = {
  en: {
    back: '← Back to TripInele',
    title: 'About TripInele',
    paragraphs: [
      "Finding the right beach holiday usually means a dozen browser tabs, half-finished comparisons, and a nagging feeling you're still missing the obvious option someone else already found. That's the part we do for you.",
      "We've already researched real prices, real climate data, and real amenities across dozens of Mediterranean destinations — the boring, time-consuming part. You answer a few quick questions about what you actually want, and we match it against what we already know, honestly — including the tradeoffs, not just the highlight reel.",
      "Skip the hours of googling. Tell us what you're after, and we'll show you where it already fits.",
    ],
    subscribeHeading: 'Want to hear about our next offer?',
    subscribeSubtitle: "No spam — just an email when we've got a genuinely good new destination or deal worth your time.",
    emailPlaceholder: 'Your email',
    subscribeButton: 'Subscribe',
    submittedMessage: "Thanks — we'll be in touch!",
    errorMessage: 'Something went wrong — mind trying again in a moment?',
  },
  de: {
    back: '← Zurück zu TripInele',
    title: 'Über TripInele',
    paragraphs: [
      'Den richtigen Strandurlaub zu finden bedeutet oft: ein Dutzend geöffneter Browser-Tabs, halbfertige Vergleiche und das nagende Gefühl, dass man vielleicht trotzdem die naheliegende Option übersieht, die jemand anderes längst gefunden hat. Genau diesen Teil übernehmen wir für dich.',
      'Wir haben bereits echte Preise, echte Klimadaten und die tatsächliche Ausstattung von Dutzenden Reisezielen am Mittelmeer recherchiert – den langweiligen und zeitaufwendigen Teil also. Du beantwortest ein paar kurze Fragen dazu, was du wirklich suchst, und wir gleichen deine Wünsche mit dem ab, was wir bereits wissen – ehrlich und mit allen wichtigen Abwägungen, nicht nur mit den schönsten Highlights.',
      'Spar dir stundenlanges Googeln. Sag uns, was du suchst, und wir zeigen dir, wo es bereits passt.',
    ],
    subscribeHeading: 'Über unser nächstes Angebot informiert werden?',
    subscribeSubtitle: 'Kein Spam – nur eine E-Mail, wenn wir ein wirklich gutes neues Reiseziel oder einen Deal haben, der deine Zeit wert ist.',
    emailPlaceholder: 'Deine E-Mail',
    subscribeButton: 'Abonnieren',
    submittedMessage: 'Danke — wir melden uns!',
    errorMessage: 'Etwas ist schiefgelaufen — magst du es gleich nochmal versuchen?',
  },
};

/**
 * Didn't exist before 2026-09-05 — owner's ask, day after launch: "da pomazemo ljudima da
 * ustede sate googlanja... mi vec imamo obradjeno". German added same day, owner's own
 * native-speaker copy (informal "du" throughout, matching the site's existing established tone
 * — see i18n.service.ts's copy-pass note) — kept as a local COPY map here rather than
 * I18nService's flat STRINGS, same reasoning as privacy-policy.ts's locale @if/@else: this is
 * full prose, not short UI chrome. The subscribe form itself (inputs/signals/submit logic) stays
 * one shared block regardless of locale — only its labels swap — so the interactive behavior
 * isn't duplicated per language. The subscribe form is capture-only — see NewsletterSubscriber
 * model — there's no send pipeline yet, so the success copy deliberately says "we'll be in
 * touch" (future), not "check your inbox" (implies something immediate that doesn't exist).
 */
@Component({
  selector: 'app-about',
  standalone: true,
  imports: [FormsModule, ButtonComponent, RouterLink],
  template: `
    <div class="mx-auto max-w-2xl px-4 py-16 text-slate-700">
      <a routerLink="/latesummer" class="mb-8 inline-block text-sm text-sky-600 underline">{{ copy().back }}</a>
      <h1 class="mb-6 text-2xl font-bold text-slate-900">{{ copy().title }}</h1>

      <div class="space-y-5 text-base leading-relaxed">
        @for (paragraph of copy().paragraphs; track $index; let last = $last) {
          <p [class.font-medium]="last" [class.text-slate-900]="last">{{ paragraph }}</p>
        }
      </div>

      <div class="mt-10 rounded-xl border border-stone-200 bg-stone-50 p-6">
        <h2 class="mb-1 text-lg font-semibold text-slate-900">{{ copy().subscribeHeading }}</h2>
        <p class="mb-4 text-sm text-slate-500">{{ copy().subscribeSubtitle }}</p>

        @if (submitted()) {
          <p class="text-sm font-medium text-emerald-700">{{ copy().submittedMessage }}</p>
        } @else {
          <form class="flex flex-col gap-2 sm:flex-row" (ngSubmit)="subscribe()">
            <input
              type="email"
              name="email"
              required
              [placeholder]="copy().emailPlaceholder"
              [(ngModel)]="email"
              class="flex-1 rounded-lg border border-stone-300 px-4 py-2.5 text-sm focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-200"
            />
            <ui-button variant="primary" [loading]="loading()" (clicked)="subscribe()">
              {{ copy().subscribeButton }}
            </ui-button>
          </form>
          @if (error()) {
            <p class="mt-2 text-sm text-red-600">{{ error() }}</p>
          }
        }
      </div>
    </div>
  `,
})
export class AboutComponent {
  email = '';
  readonly loading = signal(false);
  readonly submitted = signal(false);
  readonly error = signal<string | null>(null);
  readonly copy = computed(() => COPY[this.locale.locale()]);

  constructor(
    private gql: GraphqlService,
    private locale: LocaleService
  ) {}

  async subscribe(): Promise<void> {
    if (!this.email.trim()) return;

    this.loading.set(true);
    this.error.set(null);
    try {
      await this.gql.request(SUBSCRIBE_MUTATION, { email: this.email.trim() });
      this.submitted.set(true);
    } catch {
      this.error.set(this.copy().errorMessage);
    } finally {
      this.loading.set(false);
    }
  }
}
