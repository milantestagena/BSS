import { Routes } from '@angular/router';
import { WizardComponent } from './features/wizard/wizard';

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
  {
    path: 'latesummer',
    component: WizardComponent,
    data: {
      campaignKey: 'kasno-letovanje',
      // English rewrite, 2026-08-06 — same playful "haven't caught any sun yet" wink as the
      // original Serbian "Nisi osolio dupe ove godine?" joke, kept for an English-speaking
      // reviewer (Booking Affiliate application). See wizard_architecture memory.
      intro: {
        title: 'Still pasty this year?',
        subtitle: "Let's fix that. It's still beach weather on the Mediterranean while it's dark back home by 5pm.",
        cta: 'Get me some sun',
      },
    },
  },
];
