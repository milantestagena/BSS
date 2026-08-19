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
- **i18n:** engleski je kanonski izvor; `Translation` model + `HasTranslations` trait (hash-based staleness detection) + `TranslateDirective` (`@translate` na GraphQL polju, čita `X-Locale` header) su izgrađeni i live (nemački, 2026-08-11). Za NOVI sadržaj bez prevoda: owner eksplicitno traži od Claude-a u sesiji da prevede (nije automatizovan AI trigger/pipeline — namerna odluka, 2026-08-11, "preko ovog prompta, ja te zamolim, ti izvršiš"). Honest Report se prevodi isto — uvek generisan na engleskom prvo, pa preveden (nikad nezavisno generisan po jeziku).
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

**Status (2026-08-19):** Live na tripinele.com. **Booking.com Affiliate (CJ) prijava i dalje
Pending** — podneta 2026-08-07, prošao i 15.8 rok bez odgovora, ponovo proveren 17-19.8, i dalje
čeka se. Ne blokira dalji razvoj (owner i dalje bez žurbe), samo pomera launch-datum odluku dalje
u budućnost — ne pretpostavljati konkretan datum dok ID stvarno ne stigne.

Cela punch-lista niže (stavke 1-7) je **gotova** — kampanja "kasno letovanje" je funkcionalno
kompletna, sesija 12-19.8 je bila fokusirana na kvalitet/bagove/proširenje, ne na osnovnu
funkcionalnost. Bitno iz tog perioda:

- **Zelenortska ostrva dodata** (2026-08-19) — pravi winter-sun destinacija (more 23-25°C i u
  januaru), realno istraženi DACH čarter podaci (Sal/Boa Vista, ne nagađano). **Hrvatska
  isključena** iz kampanje (najhladnija, nikad nije dobila prave cene) — podaci NISU obrisani,
  samo otkačeni od `mediteran` region_theme-a, spremni za buduću ne-swim kampanju.
- **`toBookingUrl()`/`toBookingFlightsUrl()`** — pravi, javni, radni Booking.com linkovi (smeštaj
  i let), bez API pristupa/odobrenja, bez `dest_id` (koji je lažni placeholder od 13.7). Avio cena
  namerno NE ulazi u budžet proračun (previše promenljiva) — samo živi link na rezultat-ekranu.
- **Price_rank kolizije ispravljene** — prosek umesto minimuma za boju kartice (odvojeno od
  budget-fit odluke, koja i dalje koristi minimum), plus "Superstar" zvezdica za savršeno
  poklapanje svih izabranih vibe tagova.
- **Per-kampanja tag on/off** (`preference_tag.meta.campaign_keys`) — mehanizam da svaka kampanja
  bira svoj podskup preference_tag opcija, bez retag-ovanja postojećih.
- **`kampanje.md`** (repo root) — živ fajl za planiranje SLEDEĆIH kampanja (Zimsko sunce =
  proširenje kasno-letovanje sa egzotičnim winter-sun destinacijama za nov/dec; Jesenjovanje =
  nova city-break kampanja timovana na DACH Herbstferien) — proveriti tamo pre predlaganja
  sledećih koraka, ne duplirati ovde.

**Pre-launch punch lista (dogovoreno 2026-08-11, ažurirano isto veče):**

1. ~~Proširiti listu gradova~~ — **gotovo 2026-08-11.** 14 grčkih ostrva (Jonska, Dodekanez,
   Sporadi — Skopelos/Skijatos obavezno po owner-ovom zahtevu) + 20 novih za Egipat/Tunis/Tursku.
   Svaka lokacija potvrđena stvarnim `climate:import` (Open-Meteo) podacima za sep-nov pre
   uključivanja — nijedna nije pala ispod "cold" praga (`sea_temp_c` < 18°C). Italija/Španija/
   Portugal namerno OSTAJU kako jesu — kontinentalna mediteranska obala (npr. Lloret/Valensija)
   hladi prebrzo do novembra za ovu kampanju; ono što već imamo (Kanari za Španiju, Sardinija/
   Sicilija za Italiju, Algarve za Portugal) su već najjužnije/najtoplije tačke tih zemalja.
2. **Nedeljno ažuriranje cena** — owner šalje real cene sa terena, ubacuje se preko idempotentnog
   `WizardSeeder`-a (`db:seed --class=WizardSeeder`, bezbedno na živoj bazi). Kontinuirano, ne
   jednokratno.
   - **Odluka 2026-08-11, o automatizaciji preko Booking API-ja:** razmotreno i ODBAČENO da se
     cene automatski povlače pozivanjem Booking Partner/Demand API-ja (čak i kao agregatni
     "cena ne ide ispod X€" raspon, ne cena po konkretnom prodavcu) — rizik je što skoro svaki
     affiliate API ugovor zabranjuje "automated means"/caching Content-a izvan žive korisničke
     sesije, nezavisno od preciznosti ili namere rezultata; posledica kršenja je gašenje celog
     affiliate naloga na kom stoji ceo biznis. Nije rešeno poređenjem sa Open-Meteo (otvoren
     data API, potpuno druga svrha/ugovor) niti argumentom "isti rezultat kao ručni rad" (ugovori
     tipično zabranjuju NAČIN pristupa, ne rezultat). **Umesto toga: owner ručno pretražuje sajt
     kao gost (filter 3 osobe/1 apartman/date range, Order by Price ASC — to nije "automated
     means", to je normalna upotreba sajta) i šalje SCREENSHOT; Claude čita cenu sa slike (OCR/
     vision, ne API poziv) i ubacuje u `WizardCampaignDestinationWeeklyPrice`.** Ovo je suštinski
     isti postupak kao ranije (owner šalje real cene, Claude ih unosi), samo brže od ručnog
     kucanja. Ako se ikad odluči za pravi Booking API pristup, prvi korak je pribaviti i pročitati
     tačan tekst caching/data-retention klauzule iz partner ugovora — ne nagađati.
   - **Ekstrakcija sa screenshot-a:** NE uzimati apsolutno najjeftiniju stavku sa vrha Order by
     Price ASC liste — česta anomalija (soba bez kupatila, pogrešan unos). Uzeti 3.-4. cenu sa
     liste kao realniji "pod" za tu nedelju/destinaciju.
3. ~~Automatsko skraćivanje liste gradova po temperaturi mora~~ — **gotovo.** `filterByClimate` u
   `GeographyResolver`, računa se live protiv ciljanog meseca protiv stvarnih `TaxonomyNodeClimate`
   podataka.
4. ~~Persona↔preference_tag implies/excludes veze~~ — **gotovo 2026-08-11.**
5. ~~Popuniti country-level taxonomy meta tagove~~ — **gotovo 2026-08-11.** Novi
   `seedSwimAtmosphereTags()` popunjava `meta.atmosphere`/`meta.drinks`/`meta.food` (jedina 3+1
   ključa koje `GeographyResolver::suggested()`'s `match_score` stvarno čita — `vibe_profile` je
   samo hover-tekst, ne utiče na skor). Rave namerno strog/kratak spisak — samo destinacije čiji
   je PRIMARNI karakter zabava (Ajia Napa, St. Julian's, Bodrum, Marmaris, Mikonos, Albufeira,
   Hvar), ne mesta koja SADRŽE poznatu party četvrt (Krf/Kavos, Rodos/Faliraki, Krit/Malia —
   namerno izostavljeni, mešoviti karakter). Pub/pivo → Malta+Kipar (country-level). Hrana/vino →
   Grčka/Italija/Turska. Verifikovano tinker-om da match_score sad stvarno razlikuje rezultate.
6. ~~Nemački jezik~~ — **gotovo 2026-08-11.** `TranslateDirective` (@translate na FIELD_DEFINITION,
   čita `X-Locale` header), `LocaleService`/`I18nService` na frontu, EN/DE switch pored account
   badge-a. Poznat preostali gap: `TaxonomyNode.meta.vibe_profile.description` (hover-preview na
   screen 2) nije preveden — JSON meta polje, ne `label` koje `@translate` pokriva, treba poseban
   mehanizam kad dođe na red.
7. **Kontinuirano**: testiranje search flow-a, štelovanje `weighted_toward`/`match_score`
   vrednosti na osnovu realnih rezultata.

**Posle ove kampanje:** sajt je trajan posao, ne jednokratan projekat — svaka sledeća kampanja
ponovo pokreće mehanizam razdvajanja/proširenja taksonomije, ali uvek kao iteracija na postojeće,
ne redizajn. Konkretni planovi za sledeće kampanje (Zimsko sunce, Jesenjovanje) žive u
`kampanje.md`, ne ovde — stariji "Dočekaj Novu godinu sa Orlovima" koncept (Turneja četiri
skakaonice) je i dalje čuvan u Claude memoriji za kad dođe na red, ali nije aktivan plan.

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