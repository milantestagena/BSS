import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';
import { I18nService } from '../core/i18n.service';

/**
 * Site-wide footer — didn't exist at all before 2026-09-05 (day after launch), so the
 * Booking.com affiliate disclosure (already written into privacy-policy.ts back in August, per
 * the CJ Publisher Agreement) had nowhere visible to actually surface — nobody proactively
 * reads a Privacy Policy. See wizard.html's separate, more prominent affiliate badge near the
 * hero for the "upadljivo" (eye-catching) placement; this footer version is the quieter,
 * always-present one that also links to /about and /impressum.
 */
@Component({
  selector: 'app-footer',
  standalone: true,
  imports: [RouterLink],
  template: `
    <footer class="border-t border-stone-200 bg-stone-50 px-4 py-8 text-sm text-stone-500">
      <div class="mx-auto flex max-w-4xl flex-col items-center gap-3 text-center sm:flex-row sm:justify-between sm:text-left">
        <p>{{ i18n.t('footerAffiliateNote') }}</p>
        <nav class="flex gap-4">
          <a routerLink="/about" class="hover:text-stone-700 hover:underline">{{ i18n.t('footerAboutLink') }}</a>
          <a routerLink="/privacy" class="hover:text-stone-700 hover:underline">{{ i18n.t('footerPrivacyLink') }}</a>
          <a routerLink="/impressum" class="hover:text-stone-700 hover:underline">{{ i18n.t('footerImpressumLink') }}</a>
        </nav>
      </div>
    </footer>
  `,
})
export class FooterComponent {
  constructor(protected i18n: I18nService) {}
}
