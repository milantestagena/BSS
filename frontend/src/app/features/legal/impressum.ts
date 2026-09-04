import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';

/**
 * Required for a commercial website reaching German users (§5 DDG "Impressumspflicht") —
 * missing entirely until 2026-09-05, the day after the first DACH ad campaign went live. Cites
 * DDG, not the older TMG — the Digitale-Dienste-Gesetz replaced TMG in May 2024 and carried this
 * same obligation forward under its own §5 (owner caught this — 2026-09-05, don't cite TMG
 * again). A "ladungsfähige Anschrift" (a real, reachable address) doesn't need to be a German
 * one — the operator just needs to be genuinely reachable there, which a Serbian address
 * satisfies. TripInele has no registered business entity, so this is filed as an individual
 * (Kleinstunternehmer / hobby-scale operator), not a company — no Handelsregister/USt-IdNr
 * section, since neither applies. Revisit if that ever changes (real company registration, VAT
 * registration). NOT a substitute for real legal review — this covers the citation/structure a
 * careful non-lawyer can get right, not a guarantee every DDG obligation is met; worth a real
 * German lawyer or a service like e-recht24.de before meaningful ad spend if the owner wants
 * certainty, not just good-faith effort.
 */
@Component({
  selector: 'app-impressum',
  standalone: true,
  imports: [RouterLink],
  template: `
    <div class="mx-auto max-w-2xl px-4 py-16 text-slate-700">
      <a routerLink="/latesummer" class="mb-8 inline-block text-sm text-sky-600 underline">← Zurück zu TripInele</a>
      <h1 class="mb-2 text-2xl font-bold text-slate-900">Impressum</h1>
      <p class="mb-8 text-sm text-slate-400">Angaben gemäß § 5 DDG</p>

      <div class="space-y-6 text-sm leading-relaxed">
        <section>
          <p class="whitespace-pre-line">
            Milan Stojadinović
            Nikole Tesle 67
            18310 Bela Palanka
            Serbia
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">Kontakt</h2>
          <p>
            E-Mail: <a class="text-sky-600 underline" href="mailto:info&#64;tripinele.com">info&#64;tripinele.com</a>
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">Verantwortlich für den Inhalt</h2>
          <p>
            Milan Stojadinović (Anschrift wie oben).
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-base font-semibold text-slate-900">Haftungshinweis</h2>
          <p>
            TripInele ist ein unabhängiges Reise-Suchwerkzeug und Booking.com-Affiliate-Partner
            (siehe <a class="text-sky-600 underline" routerLink="/privacy">Datenschutzerklärung</a>).
            Trotz sorgfältiger inhaltlicher Kontrolle übernehmen wir keine Haftung für die Inhalte
            externer Links — für den Inhalt verlinkter Seiten sind ausschließlich deren Betreiber
            verantwortlich.
          </p>
        </section>
      </div>
    </div>
  `,
})
export class ImpressumComponent {}
