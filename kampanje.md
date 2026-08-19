# Kampanje — beleške i planovi

> Radni fajl, dopunjuje se po potrebi. Grupa = kampanja, podgrupa = tema unutar nje, pointi = beleške. Nije formalna dokumentacija — čitljiv snapshot, detalji i dalje i u Claude memoriji (`project_campaign_expansion_roadmap.md`).

---

## Zimsko sunce (proširenje `kasno-letovanje`, NE nova kampanja)

Cilj: kad bliže destinacije (Mediteran/Atlantik) počnu da hlade prema novembru/decembru, dodati dalje "winter sun" destinacije koje su baš tada u svom sezonskom prozoru. Target start: **početak oktobra 2026** — pokrenuti proaktivno, ne čekati podsetnik.

### DACH tražnja (istraženo, WebSearch, ne nagađano)

- Rang za zimu 2026/27: **1. Tajland** (+56% rezervacije, mešovito kulturno+ostrva, ne čist plažni, ~11h+ let) → **2. Maldivi** (+12% rezervacije, +8% cena, čist plažni/luksuz) → **3. Meksiko/Cancún** (+16% cena=jaka tražnja, pravi direktni letovi Frankfurt→Cancún, klasičan all-inclusive — najbolji "plug and play" fit) → **Dominikanska Republika** (i dalje top 3 uprkos -39%, dobro utemeljena DE paket-turizam) → **Mauricijus** (u porastu, luksuz/honeymoon, dalje/skuplje) → **Kenija** (stabilna)
- Condor zimski red letenja 2026/27 iz Frankfurta potvrđuje direktne linije: **Jamajka** (Montego Bay, sreda/subota, ~11h25min), **Barbados**, **Dominikanska Republika**
- Sezona: dry season Dec-Apr za Karibe; opšte winter-sun Nov-Apr (ista logika kao Zelenortska ostrva, već dodata 2026-08-19)

### Već rešeno (ne otvarati ponovo)

- Avio karta NE ulazi u budžet proračun — previše promenljiva cena (yield management, 10x skok pred polazak), isti razlog kao vraćanje `budget_shortfall_eur`
- Umesto broja: `SearchSessionQueryCompiler::toBookingFlightsUrl()` — pravi radni link, bez `aid`/`label` (affiliate wrapper ide posebno kad CJ odobri), prikazan na budžet koraku (napomena bez linka) i rezultat-ekranu (pravi link)
- Isti mehanizam radi automatski za svaku novu zemlju čim ima `iso_code` — nema dodatnog rada
- `toBookingUrl()` (obični Booking search) isto već radi generički, `ss=` search string ne `dest_id`

### Šta fali za svaku novu destinaciju (isti proces kao Zelenortska ostrva)

- Realni gradovi sa potvrđenim direktnim DACH čarter/redovnim letovima (istražiti, ne nagađati)
- TaxonomyNode (zemlja+gradovi) + SR/DE prevodi
- Real lat/lng → `climate:import` za pravu sea_temp
- `vibe_profile` opisi, exploration/beach/family/quiet tagovi
- Hospitality/local_stores cena meta + cultural_availability tier-ovi (istraženo, isti nivo pažnje kao halal/tap_water/lgbtq za Zelenortska ostrva)
- Accommodation season template (verovatno `winter_sun`)
- Prazni cenovni redovi (`campaign:seed-destination-price-rows`) — prave cene i dalje čekaju vlasnikov ručni Booking screenshot

### Otvoreno pitanje

- Da li ovo produžava `kasno-letovanje`-ovu `season_end_date` (trenutno 2026-11-01) dalje u decembar, ili treba svoj landing copy ("sad je zima, al evo gde je i dalje leto")? Nije odlučeno.

### Predlog redosleda dodavanja (nije odlučeno)

- Meksiko/Dominikanska Republika prvo (najbliži postojećem all-inclusive formatu) → Karibi (Jamajka/Barbados) → Maldivi/Mauricijus (viši tier, druga publika) → Tajland poslednji (najkompleksniji, mešoviti karakter)

---

## Jesenjovanje (nova, odvojena kampanja — city-break)

Ideja vlasnika, 2026-08-19 (naziv = jesen + letovanje, isti duh kao "kasno-letovanje"). Odvojeno od Zimskog sunca — ovo je `trip_type=city_break`, ne produžetak swim kampanje.

### Postojeća osnova

- `trip_type=city_break` node već postoji
- Atina/Rim već tagovani kao city-break destinacije (namerno izostavljeni iz letovanje kampanje)
- Parkirana ideja iz ranije (`project_phase2_sunny_days_tag` memorija): pravi Open-Meteo %-sunčanih-dana po gradu — namenski čuvano baš za ovu kampanju

### Kad su Nemci slobodni (Herbstferien 2026, istraženo)

- Najgušći prozor: **12-31. oktobar** kroz većinu pokrajina
  - NRW 17-31.10, Niedersachsen/Bremen/Sachsen/Schleswig-Holstein/Thüringen 12-24.10, Berlin/Hamburg/Brandenburg/Sachsen-Anhalt 19-30.10
  - Hessen/Rheinland-Pfalz/Saarland ranije: 5-17.10
  - Baden-Württemberg kasnije: 26-31.10
  - Bayern najkasnije: 2-6.11 + 18.11
- **Austrija**: uže, samo 27-31.10

### Šta vole (delimično potvrđeno pretragom, delimično opšte znanje — proveriti pre gradnje)

- Potvrđeno: Barselona trenutni "trend grad" po broju pretraga kod Nemaca, pa Pariz, pa London. Beč/Amsterdam/Rim/Venecija stalno visoko.
- NIJE sveže potvrđeno (opšte znanje, treba re-proveriti): Prag/Budimpešta/Krakov kao klasici za jeftin/blizak city-break

### Status

- Mnogo ranija faza od Zimskog sunca — nema svoje `wizard_campaign` reda, nema city-break specifičnog flow-a, nema roster-a destinacija van Atine/Rima
- Target lansiranje/marketing guranje: oko sredine oktobra, poklapa se sa Herbstferien prozorom
