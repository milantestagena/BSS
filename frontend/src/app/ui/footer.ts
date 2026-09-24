import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';
import { I18nService } from '../core/i18n.service';
import { WizardService, providerDisplayName } from '../core/wizard.service';

/**
 * Site-wide footer — didn't exist at all before 2026-09-05 (day after launch), so the
 * affiliate disclosure (already written into privacy-policy.ts back in August, per the CJ
 * Publisher Agreement) had nowhere visible to actually surface — nobody proactively reads a
 * Privacy Policy. See wizard.html's separate, more prominent affiliate badge near the hero for
 * the "upadljivo" (eye-catching) placement; this footer version is the quieter, always-present
 * one that also links to /about and /impressum.
 *
 * The disclosure names whichever provider the CURRENT campaign actually sends bookings to
 * (2026-09-17, once Hotels.com/Jesenjovanje started running side by side with Booking/
 * kasno-letovanje — see WizardCampaign::provider()) — a static "Booking.com" claim here would be
 * false, and inaccurate, for any hotels_com-provider campaign. WizardService is a root singleton
 * (see wizard.service.ts), so this component reads the SAME campaignMeta the active
 * WizardComponent instance already populated on init, no separate fetch needed. Falls back to
 * 'Booking.com' (providerDisplayName's own default) outside any campaign, matching
 * WizardCampaign::provider()'s own default.
 */
@Component({
  selector: 'app-footer',
  standalone: true,
  imports: [RouterLink],
  template: `
    <footer class="border-t border-stone-200 bg-stone-50 px-4 py-8 text-sm text-stone-500">
      <div class="mx-auto flex max-w-4xl flex-col items-center gap-3 text-center sm:flex-row sm:justify-between sm:text-left">
        <!-- Hotels.com mention is a real tracked link when one's available, 2026-09-17 (owner's
             ask) — even a casual click on the brand name plants the 7-day cookie. Booking's own
             text stays plain (footerAffiliateNote, unsplit) — see i18n.service.ts's docblock. -->
        @if (isHotelsCom && genericHotelsUrl) {
          <p class="font-semibold text-stone-600">
            {{ i18n.t('footerAffiliateNotePrefix') }}
            <a [href]="genericHotelsUrl" target="_blank" rel="noopener" class="underline hover:text-stone-800">Hotels.com</a>
            {{ i18n.t('footerAffiliateNoteSuffix') }}
          </p>
        } @else {
          <p class="font-semibold text-stone-600">{{ i18n.t('footerAffiliateNote', { provider: providerLabel }) }}</p>
        }
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
  constructor(
    protected i18n: I18nService,
    private wizard: WizardService
  ) {}

  get providerLabel(): string {
    return providerDisplayName(this.wizard.campaignMeta());
  }

  get isHotelsCom(): boolean {
    return this.wizard.campaignMeta()?.['provider'] === 'hotels_com';
  }

  get genericHotelsUrl(): string | null {
    return (this.wizard.compiledQuery()?.['genericHotelsUrl'] as string | undefined) ?? null;
  }
}
