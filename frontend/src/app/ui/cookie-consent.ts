import { Component, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AnalyticsService } from '../core/analytics.service';
import { I18nService } from '../core/i18n.service';

/**
 * GDPR consent gate for the Meta Pixel — see AnalyticsService's docblock for why this exists
 * (2026-09-05, the day after the first ad campaign went live: no consent mechanism existed at
 * all before this, a real compliance gap now that the site actually sets a third-party tracking
 * cookie). Shown once per browser (localStorage-remembered choice); "Decline" is a real choice,
 * not a dark pattern — the Pixel simply never loads if declined, the site works identically
 * either way (no feature is gated behind tracking).
 *
 * Static (not `fixed`), 2026-09-05 — owner's call: rather than pin this over the viewport and
 * reserve matching bottom padding elsewhere so it never covers page content, App's own layout
 * gives the router-outlet its own internally-scrolling region (see app.html) with this banner
 * as a normal sibling below it — simpler, and avoids the address-bar-resize jumpiness a `fixed`
 * bottom element causes on mobile browsers ("bolje za mobilni, da ne skakuce").
 */
@Component({
  selector: 'app-cookie-consent',
  standalone: true,
  imports: [RouterLink],
  template: `
    @if (visible()) {
      <div class="border-t border-stone-700 bg-stone-900/95 px-4 py-4 text-stone-100">
        <div class="mx-auto flex max-w-4xl flex-col items-center gap-3 sm:flex-row sm:justify-between">
          <p class="text-sm leading-relaxed">
            {{ i18n.t('cookieConsentText') }}
            <a routerLink="/privacy" class="underline hover:text-amber-300">{{ i18n.t('footerPrivacyLink') }}</a>
          </p>
          <div class="flex shrink-0 gap-2">
            <button
              type="button"
              class="rounded-lg border border-stone-500 px-4 py-2 text-sm font-medium text-stone-200 hover:bg-stone-800"
              (click)="respond('declined')"
            >
              {{ i18n.t('cookieConsentDecline') }}
            </button>
            <button
              type="button"
              class="rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-amber-400"
              (click)="respond('accepted')"
            >
              {{ i18n.t('cookieConsentAccept') }}
            </button>
          </div>
        </div>
      </div>
    }
  `,
})
export class CookieConsentComponent {
  readonly visible = signal(false);

  constructor(
    private analytics: AnalyticsService,
    protected i18n: I18nService
  ) {
    this.visible.set(this.analytics.getStoredConsent() === null);
  }

  respond(choice: 'accepted' | 'declined'): void {
    this.analytics.setConsent(choice);
    this.visible.set(false);
  }
}
