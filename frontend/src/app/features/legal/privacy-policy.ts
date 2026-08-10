import { Component } from '@angular/core';

/**
 * Required by the CJ Affiliate Publisher Service Agreement (Section 2(e)/6) as a condition of
 * running Booking.com affiliate links on this site — 2026-08-10. Written to describe what this
 * app actually does (wizard answers, IP-based home-city convenience lookup, CJ/Booking
 * tracking on click-through) rather than generic boilerplate. Update this if the app's actual
 * data practices change — e.g. once real user accounts/auth exist (see CLAUDE.md phase plan).
 */
@Component({
  selector: 'app-privacy-policy',
  standalone: true,
  template: `
    <div class="mx-auto max-w-2xl px-4 py-16 text-slate-700">
      <h1 class="mb-2 text-2xl font-bold text-slate-900">Privacy Policy</h1>
      <p class="mb-8 text-sm text-slate-400">Last updated: 10 August 2026</p>

      <div class="space-y-6 text-sm leading-relaxed">
        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">Who we are</h2>
          <p>
            TripInele ("we", "us") operates this website to help you find a travel destination
            that fits what you're looking for. If you have questions about this policy, contact
            us at <a class="text-sky-600 underline" href="mailto:hello&#64;tripinele.com">hello&#64;tripinele.com</a>.
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">What we collect</h2>
          <ul class="list-disc space-y-1 pl-5">
            <li>
              <strong>Your wizard answers</strong> — trip type, group size, ages, budget,
              preferences, and destination choices you enter while using the site. These are
              stored to generate and display your recommendations during your session.
            </li>
            <li>
              <strong>Approximate location</strong> — we use your IP address to guess your home
              city, purely as a convenience so you don't have to type it. This lookup is
              best-effort and never blocks or delays the wizard if it fails.
            </li>
            <li>
              <strong>Standard technical logs</strong> — IP address, browser type, and request
              timestamps, collected automatically by our server infrastructure for security and
              debugging, like virtually every website.
            </li>
          </ul>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">Cookies &amp; third-party tracking</h2>
          <p>
            When you click through to Booking.com from this site, Booking.com and its affiliate
            network (CJ Affiliate / Commission Junction, operated by Epsilon International UK
            Ltd) may set cookies or similar tracking technology on your device to record that
            the referral came from us — this is how we may earn a commission if you book. We do
            not control this tracking; it's governed by
            <a class="text-sky-600 underline" href="https://www.cj.com/privacy-notice" target="_blank" rel="noopener">CJ Affiliate's own privacy policy</a>
            and <a class="text-sky-600 underline" href="https://www.booking.com/content/privacy.html" target="_blank" rel="noopener">Booking.com's privacy policy</a>.
            Our own server may also set a minimal technical session cookie needed for the site
            to function — this is not used for advertising or tracking you across other sites.
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">How we use this data</h2>
          <p>
            Solely to run the recommendation wizard, show you relevant suggestions, and measure
            whether the referrals we send to Booking.com result in a booking. We do not sell
            your data, and we do not use it for anything beyond making this site work.
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">How long we keep it</h2>
          <p>
            Wizard session data is kept only as long as needed to provide the service and for a
            reasonable period afterward for debugging and fraud prevention, then deleted or
            anonymized.
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">Your rights</h2>
          <p>
            If you're in the EU/UK, you have the right to access, correct, or request deletion
            of your data under GDPR. Since this site doesn't currently require an account or
            collect your name/email during the wizard itself, most sessions aren't directly
            identifiable to us — but if you believe we hold data about you and want it removed,
            email <a class="text-sky-600 underline" href="mailto:hello&#64;tripinele.com">hello&#64;tripinele.com</a> and we'll act on it.
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">Changes to this policy</h2>
          <p>
            We may update this policy as the site evolves (e.g. if we add user accounts). Any
            changes will be posted on this page.
          </p>
        </section>
      </div>
    </div>
  `,
})
export class PrivacyPolicyComponent {}
