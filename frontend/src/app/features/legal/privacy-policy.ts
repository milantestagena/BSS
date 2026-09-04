import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';
import { LocaleService } from '../../core/locale.service';

/**
 * Required by the CJ Affiliate Publisher Service Agreement (Section 2(e)/6) as a condition of
 * running Booking.com affiliate links on this site — 2026-08-10. Written to describe what this
 * app actually does (wizard answers, IP-based home-city convenience lookup, CJ/Booking
 * tracking on click-through) rather than generic boilerplate. Update this if the app's actual
 * data practices change — e.g. once real user accounts/auth exist (see CLAUDE.md phase plan).
 *
 * German version added 2026-09-05 — owner's native-speaker review: "Datenschutzerklärung" as
 * the DE title (not a literal "Privacy Policy"), naturally translated rather than word-for-word.
 * Locale-switched here (not through I18nService's flat STRINGS map) since this is full prose,
 * not short UI chrome — matches wizard.html's own `intro.en`/`intro.de` pattern for the same
 * reason. Both languages updated same day to disclose the Meta Pixel (added the night before,
 * consent-gated — see AnalyticsService/CookieConsentComponent) and the newsletter email capture
 * (About page) — neither existed when this policy was first written.
 */
@Component({
  selector: 'app-privacy-policy',
  standalone: true,
  imports: [RouterLink],
  template: `
    <div class="mx-auto max-w-2xl px-4 py-16 text-slate-700">
      <a routerLink="/latesummer" class="mb-8 inline-block text-sm text-sky-600 underline">
        {{ locale.locale() === 'de' ? '← Zurück zu TripInele' : '← Back to TripInele' }}
      </a>
      @if (locale.locale() === 'de') {
        <h1 class="mb-2 text-2xl font-bold text-slate-900">Datenschutzerklärung</h1>
        <p class="mb-8 text-sm text-slate-400">Zuletzt aktualisiert: 5. September 2026</p>

        <div class="space-y-6 text-sm leading-relaxed">
          <section>
            <h2 class="mb-2 text-base font-semibold text-slate-900">Wer wir sind</h2>
            <p>
              TripInele („wir", „uns") betreibt diese Website, um Ihnen dabei zu helfen, ein
              Reiseziel zu finden, das Ihren Vorstellungen entspricht. Wenn Sie Fragen zu dieser
              Datenschutzerklärung haben, kontaktieren Sie uns unter
              <a class="text-sky-600 underline" href="mailto:info&#64;tripinele.com">info&#64;tripinele.com</a>.
            </p>
          </section>

          <section>
            <h2 class="mb-2 text-base font-semibold text-slate-900">Welche Daten wir erheben</h2>
            <ul class="list-disc space-y-1 pl-5">
              <li>
                <strong>Ihre Antworten im Reise-Assistenten</strong> — Reisetyp, Gruppengröße,
                Alter, Budget, Präferenzen und von Ihnen ausgewählte Reiseziele, die Sie während
                der Nutzung der Website eingeben. Diese Angaben werden gespeichert, um während
                Ihrer Sitzung Empfehlungen zu erstellen und anzuzeigen.
              </li>
              <li>
                <strong>Ungefährer Standort</strong> — wir verwenden Ihre IP-Adresse, um Ihre
                Heimatstadt ungefähr zu ermitteln. Dies dient ausschließlich der Vereinfachung,
                damit Sie Ihren Wohnort nicht selbst eingeben müssen. Diese Abfrage blockiert
                oder verzögert den Reise-Assistenten nicht, falls sie fehlschlägt.
              </li>
              <li>
                <strong>Standardmäßige technische Protokolldaten</strong> — IP-Adresse,
                Browsertyp und Zeitstempel von Anfragen, automatisch erfasst von unserer
                Server-Infrastruktur zu Sicherheits- und Fehlerbehebungszwecken, wie bei
                praktisch jeder Website.
              </li>
              <li>
                <strong>Ihre E-Mail-Adresse</strong>, falls Sie sich für unseren Newsletter
                anmelden — nur wenn Sie sich aktiv dafür entscheiden, gelegentliche Updates zu
                neuen Reisezielen oder Angeboten zu erhalten; an keiner anderen Stelle fragen wir
                danach.
              </li>
            </ul>
          </section>

          <section>
            <h2 class="mb-2 text-base font-semibold text-slate-900">Cookies &amp; Tracking durch Dritte</h2>
            <p class="mb-3">
              Wenn Sie von dieser Website zu Booking.com weitergeleitet werden, können
              Booking.com und dessen Affiliate-Netzwerk (CJ Affiliate / Commission Junction,
              betrieben von Epsilon International UK Ltd) Cookies oder ähnliche
              Tracking-Technologien auf Ihrem Gerät setzen, um zu erfassen, dass die
              Weiterleitung von uns stammt — so können wir eine Provision erhalten, wenn Sie
              buchen. Wir haben keinen Einfluss auf dieses Tracking; es unterliegt der
              <a class="text-sky-600 underline" href="https://www.cj.com/privacy-notice" target="_blank" rel="noopener">Datenschutzerklärung von CJ Affiliate</a>
              und der <a class="text-sky-600 underline" href="https://www.booking.com/content/privacy.html" target="_blank" rel="noopener">Datenschutzerklärung von Booking.com</a>.
            </p>
            <p class="mb-3">
              Mit Ihrer Einwilligung (siehe das Cookie-Banner bei Ihrem ersten Besuch) verwenden
              wir außerdem das Meta/Facebook-Pixel, um die Leistung unserer Anzeigen zu messen.
              Dabei wird ein Cookie gesetzt und es werden begrenzte technische Daten (z. B.
              welche Seite Sie angesehen haben) an Meta übermittelt. Es wird nur geladen, wenn
              Sie zustimmen — eine Ablehnung schränkt keine andere Funktion der Website ein.
              Details finden Sie in der
              <a class="text-sky-600 underline" href="https://www.facebook.com/privacy/policy/" target="_blank" rel="noopener">Datenschutzerklärung von Meta</a>.
            </p>
            <p>
              Unser eigener Server kann außerdem ein minimales technisches Sitzungscookie setzen,
              das für die Funktion der Website erforderlich ist — dieses wird nicht für Werbung
              oder zur Nachverfolgung über andere Websites hinweg verwendet.
            </p>
          </section>

          <section>
            <h2 class="mb-2 text-base font-semibold text-slate-900">Wie wir diese Daten verwenden</h2>
            <p>
              Ausschließlich, um den Reise-Assistenten zu betreiben, Ihnen relevante Empfehlungen
              anzuzeigen und zu messen, ob die von uns an Booking.com weitergeleiteten Besucher
              dort eine Buchung vornehmen. Wir verkaufen Ihre Daten nicht und verwenden sie nicht
              für andere Zwecke als den Betrieb und die Verbesserung dieser Website.
            </p>
          </section>

          <section>
            <h2 class="mb-2 text-base font-semibold text-slate-900">Wie lange wir Daten speichern</h2>
            <p>
              Die Daten aus Ihrer Sitzung im Reise-Assistenten werden nur so lange gespeichert,
              wie dies zur Erbringung des Dienstes erforderlich ist, sowie für einen angemessenen
              Zeitraum danach zur Fehlerbehebung und zur Betrugsprävention — anschließend werden
              sie gelöscht oder anonymisiert.
            </p>
          </section>

          <section>
            <h2 class="mb-2 text-base font-semibold text-slate-900">Ihre Rechte</h2>
            <p>
              Wenn Sie sich in der EU oder im Vereinigten Königreich befinden, haben Sie gemäß
              der DSGVO das Recht auf Auskunft über Ihre personenbezogenen Daten sowie deren
              Berichtigung oder Löschung. Da diese Website derzeit kein Benutzerkonto erfordert
              und Sie während der Nutzung des Reise-Assistenten weder Namen noch E-Mail-Adresse
              angeben müssen, sind die meisten Sitzungen für uns nicht direkt einer Person
              zuzuordnen — falls Sie dennoch glauben, dass wir Daten über Sie gespeichert haben,
              und deren Löschung wünschen, kontaktieren Sie uns unter
              <a class="text-sky-600 underline" href="mailto:info&#64;tripinele.com">info&#64;tripinele.com</a>.
            </p>
          </section>

          <section>
            <h2 class="mb-2 text-base font-semibold text-slate-900">Änderungen dieser Datenschutzerklärung</h2>
            <p>
              Wir können diese Datenschutzerklärung aktualisieren, wenn sich die Website
              weiterentwickelt (z. B. bei Einführung von Benutzerkonten). Änderungen werden auf
              dieser Seite veröffentlicht.
            </p>
          </section>
        </div>
      } @else {
        <h1 class="mb-2 text-2xl font-bold text-slate-900">Privacy Policy</h1>
        <p class="mb-8 text-sm text-slate-400">Last updated: 5 September 2026</p>

        <div class="space-y-6 text-sm leading-relaxed">
          <section>
            <h2 class="mb-2 text-base font-semibold text-slate-900">Who we are</h2>
            <p>
              TripInele ("we", "us") operates this website to help you find a travel destination
              that fits what you're looking for. If you have questions about this policy, contact
              us at <a class="text-sky-600 underline" href="mailto:info&#64;tripinele.com">info&#64;tripinele.com</a>.
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
              <li>
                <strong>Your email</strong>, if you subscribe — only if you actively choose to
                sign up for occasional updates about new destinations or offers; we don't ask for
                it anywhere else.
              </li>
            </ul>
          </section>

          <section>
            <h2 class="mb-2 text-base font-semibold text-slate-900">Cookies &amp; third-party tracking</h2>
            <p class="mb-3">
              When you click through to Booking.com from this site, Booking.com and its affiliate
              network (CJ Affiliate / Commission Junction, operated by Epsilon International UK
              Ltd) may set cookies or similar tracking technology on your device to record that
              the referral came from us — this is how we may earn a commission if you book. We do
              not control this tracking; it's governed by
              <a class="text-sky-600 underline" href="https://www.cj.com/privacy-notice" target="_blank" rel="noopener">CJ Affiliate's own privacy policy</a>
              and <a class="text-sky-600 underline" href="https://www.booking.com/content/privacy.html" target="_blank" rel="noopener">Booking.com's privacy policy</a>.
            </p>
            <p class="mb-3">
              With your consent (see the cookie banner shown on your first visit), we also use
              the Meta/Facebook Pixel to measure how our ads perform. This sets a cookie and
              shares limited technical data (like which page you viewed) with Meta. It never
              loads unless you accept — declining doesn't limit any other part of the site. See
              <a class="text-sky-600 underline" href="https://www.facebook.com/privacy/policy/" target="_blank" rel="noopener">Meta's own privacy policy</a>
              for how they handle this data.
            </p>
            <p>
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
              email <a class="text-sky-600 underline" href="mailto:info&#64;tripinele.com">info&#64;tripinele.com</a> and we'll act on it.
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
      }
    </div>
  `,
})
export class PrivacyPolicyComponent {
  constructor(protected locale: LocaleService) {}
}
