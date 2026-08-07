# CLAUDE.md — Booking Suggestion Site (BSS)

> Ovaj fajl se automatski učitava od strane Claude Code na početku svake sesije u ovom repou. Sadrži kompletan kontekst projekta — arhitekturu, finalizovane odluke, i trenutni status. Ažuriraj ga kad god se donese nova bitna odluka.

---

## 1. Svrha projekta

BSS je AI-assisted alat za pretragu smeštaja namenjen porodicama/putnicima koji pate od "decision fatigue" prilikom bukiranja preko Booking.com/Airbnb affiliate linkova. Osnovni mehanizam je strukturirani wizard koji vodi korisnika kroz niz odluka do personalizovane preporuke, uz AI-generisan "Honest Report" (Pros/Cons na osnovu recenzija).

Cilj: ~5.000€/mesec prihoda od affiliate provizija, što odgovara ~110–145 potvrđenih bukinga mesečno pri tipičnim porodičnim vrednostima rezervacije.

**Working style:** Claude je co-arhitekta, ne instruktor. Direktne preporuke, iskren pushback na slabije ideje, senior-level rasprava. Radni jezik: srpski, sa engleskom tehničkom terminologijom.

---

## 2. Tehnološki stack (FINALIZOVANO — ne otvarati ponovo)

- **Backend:** Laravel 13 (PHP 8.4)
- **API layer:** GraphQL preko Lighthouse
- **Database:** PostgreSQL
- **Queues/Cache:** Redis + Horizon
- **Admin panel:** FilamentPHP
- **Frontend:** Angular 21+ sa Signals
- **AI:** GPT-4o-mini (troškovi validirani kao zanemarljivi u odnosu na affiliate prihod po sesiji)
- **Isključeno:** Neo4j (uklonjen kao nepotrebna kompleksnost)

---

## 3. Finalizovane arhitekturne odluke (NE RELITIGIRATI)

- Krediti se troše **po kompletnoj search sesiji**, ne po AI pozivu ili po polju.
- Login/credit gate se primenjuje **samo na koraku konkretnog smeštaja** (korak 8 u wizard flow-u).
- Slobodni tekstualni unosi ("Imaš bolju ideju") unutar sesije **ne troše dodatne kredite** — korisnici se aktivno ohrabruju da ih koriste.
- Honest Report se **uvek prevodi sa kanonskog engleskog izvora**, nikad se ne generiše nezavisno po jeziku (radi faktičke konzistentnosti).
- **i18n:** engleski je kanonski izvor; lazy on-demand AI prevod sa "AI translated, see original" disclaimer-om; `translation_status` polje + hash-based staleness detection.
- Referral nagrade (user-to-user) se aktiviraju **samo na potvrđen booking**, nikad na signup (anti-abuse).
- Anti-abuse se oslanja na **credit scarcity + silent rate limiting**, ne na punitivni UX.

---

## 4. Wizard flow (finalni redosled)

1. **Tip putovanja** (city break / snow / summer sea) — podržava URL pre-fill za plaćeni sezonski traffic
2. **Broj putnika** (samac / par / društvo / porodica)
3. **Grubi termin/sezona** — filtrira taksonomiju + pre-popunjava Booking API parametar
4. **Persona/tip interesovanja** — filtrirano na osnovu prethodnih odgovora
5. **Grananje pitanja po personi** (iz baze)
6. **Predlog zemlje/regije** — preko taxonomy mapping-a
7. **Grad**
8. **Konkretne preference smeštaja** — iza login/credit gate-a

---

## 5. Credit / Wallet sistem

**Entiteti:**
- `User` — standardni Laravel model
- `Wallet` — `hasOne` na User, čuva `balance`
- `CreditTransaction` — log svih promena (`user_id`, `amount`, `type` [welcome/booking/manual_bonus], `description`)

**Logika:**
- Registracija → 5 besplatnih kredita (welcome bonus)
- Potvrđen booking → +20 kredita (kumulativno, po potvrdi preko postback-a ili ručne verifikacije)
- Pre svakog AI query poziva → middleware proverava `wallet.balance > 0`; ako da, skida 1 kredit
- `CheckAiCredits` middleware blokira AI upit ako je balance ≤ 0
- `BookingConfirmed` event/listener automatski dodeljuje kredite

**Filament:** widget za pregled balansa korisnika u admin panelu (MVP nivo, ne treba komplikovan UI).

---

## 6. Influencer affiliate commission sistem (u razvoju — arhitektura definisana)

Ovo je **konceptualno i arhitekturno odvojeno** od user-to-user referral sistema (koji plaća u internim kreditima). Influencer program plaća **realne novčane provizije**.

**Entiteti:**
- `ReferralPartner`
- `ReferralCode`
- `ReferralAttribution` — first-touch, **trajno zaključan** po korisniku
- `CommissionShare` — sa reconciliation statusom

**Pravila:**
- `share_percentage` je **per-partner pregovarljivo polje**, ne globalna konstanta
- Decay-tier model: **50% na prvi booking, 15% na drugi, 5% na treći, 0% dalje**
- Strategija pokretanja: prvi influencer partner dobija do **100% na prvi booking** kao podsticaj da dovede druge mikro-influensere; self-referred partneri dalje dobijaju standardnih 50%
- Filament admin widget za praćenje obaveza prema partnerima + ručni mesečni payout batching
- **Booking.com Details API** (tačan € iznos provizije po pojedinačnom bukingu) zahteva 20.000+ bukinga godišnje — ispod tog praga radimo sa procenom (prosečna affiliate stopa) po bukingu + mesečni reconciliation preko CSV izveštaja iz Partner Centre-a. `CommissionShare.status`: `pending` → `reconciled` → `paid`.
- Anti-abuse za influencer proviziju: partner ne može biti attributed na sopstveni kod (self-referral blok); velocity check ako puno signup-a stigne sa istog IP-a/device fingerprinta u kratkom roku (flag za ručni pregled); isplata tek posle isteka cancellation window-a bukinga.
- Odnos sa partnerima je **ručno pregovaran po partneru**, ne samoposlužni affiliate self-signup — bar dok se model ne validira na par partnera.

---

## 7. Marketing & monetizacija — status

- **CAC nepoznat** — prioritet je testiranje malim ad kampanjama (~10–15€/dan na Facebook/Instagram) pre commit-a na bilo koji kanal
- Organski kanali u planu: Facebook parent grupe (community seeding), SEO content (dugoročno, bez troška)
- Attribution cookie window kod Booking.com/Airbnb je kratak → jake CTA odmah posle Honest Report-a su kritične za hvatanje konverzije pre isteka attribution-a

---

## 8. Trenutni fokus / sledeći koraci

1. **Definisati `PassengerProfile` / `SearchSession` JSON shape** + DB schema/migracije (sledeća konkretna radna sesija)
2. **Thin MVP prvo** — bez AI Honest Report-a — da se validira conversion signal pre punog build-a
3. Struktura: `TaxonomyNode` (self-referencing stablo: `id, parent_id, type, slug, sort_order, meta jsonb`), `WizardStep`/`WizardQuestion`
4. Paralelno (non-technical): affiliate program aplikacije, registracija firme, GDPR compliance
5. Validacija CAC-a kroz male ad testove pre skaliranja

**Pristup:** Phased implementation — validirati conversion signal sa thin MVP-om pre investiranja u pun AI feature set. Deo-time development, odluke se tretiraju kao rešene (bez ponovnog otvaranja) osim ako nema novih podataka.

---

## 9. Ključna zapažanja (da se ne zaborave)

- Unit economics su validirani na target volumenu — nepoznata varijabla je acquisition cost (CAC), to je prioritet.
- AI troškovi po sesiji su zanemarljivi u odnosu na affiliate prihod — credit scarcity je UX/business alat, ne cost-control mera.
- Influencer i user referral sistemi moraju ostati arhitektonski i konceptualno odvojeni (izbegavanje konfuzije u payout-ima i računovodstvu).

---

## 10. Alati i eksterni resursi

- Laravel, Angular, PostgreSQL, Redis/Horizon, FilamentPHP, GPT-4o-mini, Lighthouse (GraphQL)
- Booking.com i Airbnb affiliate programi
- Facebook/Instagram za paid acquisition testiranje i organic community seeding
- Google Drive za projektnu dokumentaciju (referentni dokument sa odlukama)