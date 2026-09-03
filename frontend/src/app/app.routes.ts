import { Routes } from '@angular/router';
import { WizardComponent } from './features/wizard/wizard';
import { PrivacyPolicyComponent } from './features/legal/privacy-policy';
import { AccountPageComponent } from './features/account/account-page';

/**
 * `data.campaignKey` matches a `wizard_campaigns.key` row (backend) — the SINGLE place a
 * campaign is resolved from, see WizardComponent/WizardService, wizard_architecture memory
 * 2026-07-30. `data.intro` is the landing copy shown before the session actually starts (see
 * WizardComponent.startThemed()) — kept here rather than in the DB for now, matching this
 * project's existing "route entry = content, not code" convention.
 *
 * Path is `/latesummer` for now (owner's explicit call, 2026-07-30) — production plan is a
 * per-campaign subdomain (e.g. kasnoletovanje.domain.com) instead. Nothing below this route
 * table needs to change for that move: WizardComponent/WizardService only ever see the
 * resolved `campaignKey` string, never the URL shape it came from.
 */
export const routes: Routes = [
  // Bare root redirects straight to the one live campaign — owner's call, 2026-08-07: a
  // reviewer landing on the root domain with no path shouldn't have to guess where to go.
  // Revisit once more than one campaign is live (root would then need a real chooser, not
  // a hardcoded redirect to just one of them).
  { path: '', redirectTo: 'latesummer', pathMatch: 'full' },
  // Required by the CJ Affiliate Publisher Service Agreement as a condition of running
  // Booking.com affiliate links — 2026-08-10.
  { path: 'privacy', component: PrivacyPolicyComponent },
  { path: 'account', component: AccountPageComponent },
  {
    path: 'latesummer',
    component: WizardComponent,
    data: {
      campaignKey: 'kasno-letovanje',
      // Replaced 2026-09-03 (owner's ask, 2026-09-02: the old "Still pasty this year?"/
      // "dark by 5pm" framing read like early autumn, but it was still full scorching-summer
      // heat when this shipped — mismatched the actual season on the page. New copy leads with
      // urgency ("haven't gone yet??? what are you waiting for") instead of a seasonal-contrast
      // joke, so it doesn't go stale as fast. Original English "Still pasty" version (itself a
      // translation of the Serbian "Nisi osolio dupe ove godine?" joke) preserved in git history.
      // CTA deliberately NOT "Get me some sun" — real heatwave right now (35°C in September,
      // per owner), so "give me sun" reads backwards; save that phrasing for a colder/rainier
      // month's campaign instead. German added 2026-08-11 (DACH market) — see
      // WizardComponent.themeIntro for the locale lookup.
      intro: {
        en: {
          title: "Haven't been to the sea yet this year???",
          subtitle: "It's still beach weather on the Mediterranean, don't miss it.",
          cta: 'Take me to the beach',
        },
        de: {
          title: 'Warst du dieses Jahr noch nicht am Meer???',
          subtitle: 'Am Mittelmeer ist noch Strandwetter, verpass es nicht.',
          cta: 'Ab an den Strand',
        },
      },
    },
  },
];
