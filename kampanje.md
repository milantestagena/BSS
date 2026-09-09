# Kampanje — beleške i planovi

> Radni fajl, dopunjuje se po potrebi. Grupa = kampanja, podgrupa = tema unutar nje, pointi = beleške. Nije formalna dokumentacija — čitljiv snapshot, detalji i dalje i u Claude memoriji (`project_campaign_expansion_roadmap.md`).

---

## Zimsko sunce (SOPSTVENA kampanja, ne produžetak `kasno-letovanje`)

Cilj: kad bliže destinacije (Mediteran/Atlantik) počnu da hlade prema novembru/decembru, dodati dalje "winter sun" destinacije koje su baš tada u svom sezonskom prozoru. Target start: **početak oktobra 2026** — pokrenuti proaktivno, ne čekati podsetnik.

**Status (2026-09-09): prva faza IZGRAĐENA I LOKALNO TESTIRANA (nije deploy-ovano).** Meksiko
(Cancún) + Dominikanska Republika (Punta Cana, Puerto Plata) — sopstven `wizard_campaign` red
(`zimsko-sunce`, sezona 2026-12-01 → 2027-04-01), sopstven `termin_category` (`zimsko_sunce`,
preset — vidi "Otvoreno pitanje" ispod, rešeno), nov `dalje_sunce` region_theme (NE `mediteran`),
`/wintersun` frontend ruta (isti generički WizardComponent mehanizam kao `/latesummer`). Real
Open-Meteo klima potvrđena za sva tri grada — sea_temp_c 26-29°C kroz ceo Dec-Apr prozor, daleko
iznad 18°C praga. Prazni cenovni redovi skafoldovani (77 destinacija x 18 nedelja) — čekaju
vlasnikove screenshot cene. Draft EN/DE landing copy napisan, čeka pregled/izmenu tona. 199
backend testova prolazi. Sledeći korak po `kampanje.md`'s sopstvenom redosledu ispod: Karibi
(Jamajka/Barbados), pa Maldivi/Mauricijus, pa Tajland poslednji.

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

### Otvoreno pitanje — REŠENO 2026-09-09

- Vlasnikova arhitekturna odluka ove sesije: **sopstvena kampanja, ne produžetak** `kasno-letovanje`-ove `season_end_date`. Svoj `wizard_campaign` red, svoj `termin_category` (`zimsko_sunce`), svoj landing copy/ruta (`/wintersun`, "sad je zima kod nas, al evo gde je i dalje leto" duh — vidi draft copy u `app.routes.ts`/`WizardSeeder::seedGermanTranslations()`). Razlog isti kao za `kasno_kupanje` samo: potpuno drugi vremenski prozor + potpuno druga geografija zaslužuju sopstvenu temu, ne grananje unutar postojeće.

### Predlog redosleda dodavanja (nije odlučeno)

- Meksiko/Dominikanska Republika prvo (najbliži postojećem all-inclusive formatu) → Karibi (Jamajka/Barbados) → Maldivi/Mauricijus (viši tier, druga publika) → Tajland poslednji (najkompleksniji, mešoviti karakter)

---

## LateSummerAllInclusive (nova, odvojena kampanja — brza, gradi se na postojećem)

Ideja vlasnika, 2026-09-02, izašla direktno iz istog session-a koji je otkrio zašto se `mealplan=`
filter morao izbaciti sa Booking linka za `kasno-letovanje`: unutar JEDNE mešovite kampanje
(budžetski + povremeno all-inclusive smeštaj u istom gradu), ponuda pansiona je previše tanka/
šarena da bi bilo koja formula pouzdano procenila cenu — realni primeri iz istog dana: Öludeniz
440€/8 noći bez jela naspram 880€ sa polupansionom (2x), Retimno 200€ bez jela naspram 2500€ sa
polupansionom (12.5x), sve za ISTI grad/datume. Rešenje NIJE bolja formula — rešenje je posebna
kampanja gde je all-inclusive NORMA, ne izuzetak, pa taj problem prirodno nestaje.

### Zašto je jeftino graditi (skoro sve već postoji)

- `WizardCampaign.preset_answers` mehanizam (isti kao `termin_category` za kasno-letovanje) —
  `meal_style` i `meal_plan_preference` (npr. `sve_ukljuceno`) se presetuju, pitanja se nikad ne
  renderuju
- Realne all-inclusive cene idu direktno u `WizardCampaignDestinationPrice.includes_meals=true` —
  ovo POTPUNO zaobilazi `BudgetEstimationEngine`-ov food-estimate mehanizam (vidi `fitFor()`-ov
  `$mealsIncluded` granu), nema pogađanja, samo prava cena naspram budžeta
- Cela ostala mašinerija (taxonomy, klima, cultural_availability, GeographyResolver filtriranje,
  `budgetFitPercent`) radi bez izmena — nov red u istoj arhitekturi, ne redizajn

### Kandidati (5-6 destinacija poznatih po all-inclusive ponudi)

- Tunis, Egipat (očigledni, vlasnikovi prvi predlozi) — gradovi već postoje kao taxonomy node-ovi
  iz `kasno-letovanje` (Hurghada/Sharm El Sheikh/Marsa Alam već i imaju `includes_meals` napomenu
  u kodu za Egipat)
- Turska (predlog, nije još potvrđen vlasnikom) — možda i najpoznatija all-inclusive destinacija
  od sve tri; Alanya/Antalya i drugi gradovi već postoje, samo bi dobili drugi cenovni red za ovu
  kampanju (jedan grad može imati cene za VIŠE kampanja istovremeno, `(campaign, destination)` par)

### Status

- Samo ideja, nije počelo — vlasnikov stav 2026-09-02: "onda nas ceka ozbiljniji posao", zapisano
  za sledeću sesiju, ne za usred trenutnog šminkanja

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

---

## Arhitektura — ideje koje važe za VIŠE kampanja (ne specifično za jednu)

Dogovoreno 2026-08-19. **Prioritet: tag on/off sistem, radimo uskoro** (ne "kad-tad", stavka za sledeću sesiju odmah).

### Tag on/off po kampanji (prioritet — sledeći korak)

- Ideja: kampanja bira svoj podskup AKTIVNIH `preference_tag`-ova (npr. `lepe_plaze` ima smisla za letovanje, nema za Jesenjovanje; božićna pijaca ima smisla za Jesenjovanje/Zimsko sunce oko decembra, nikako za letovanje)
- Arhitektonski se prirodno nastavlja na već postojeći `wizard_campaign_questions` pivot obrazac (kampanja bira svoj podskup PITANJA) — isti princip, samo jedan nivo dublje, za TAGOVE unutar preference_tags pitanja
- Nije nagađanje/novi koncept, čist produžetak postojećeg
- **Izgrađeno 2026-08-19**: `meta.campaign_keys` niz na `preference_tag` node-u (ne nova pivot tabela — jednostavnije, isti obrazac kao svaki drugi meta podatak u projektu). Odsutno = dostupno svuda (default, nijedan postojeći tag ne treba retag). Sesija bez kampanje vidi sve, bez isključivanja. 4 nova testa u `GeographyResolverTest`, svi prolaze.

### Vizni zahtevi — informativni flag (vezano za Zimsko sunce/egzotiku, manje hitno)

- Iskra ideje: Šri Lanka post koji je vlasnik podelio (dobar format sam po sebi — hook cena+destinacija → mapa/itinerar → troškovi → saveti → slike, vredi kao template za budući marketing content)
- Realan problem: van EU/lakih destinacija (Egipat/Turska/Tunis/Zelenortska verovatno jednostavni za DACH pasoše, Šri Lanka/Tajland i slično često traže e-visa sa rokom unapred) korisnik može da se iznenadi u zadnji čas
- Isti obrazac kao avio-cena caveat — NE tvrditi tačna pravila (menjaju se, ne želimo lažnu preciznost), samo informativni flag + link na zvaničan izvor čim se izabere destinacija
- Vezano za dodavanje egzotičnih destinacija (vidi Zimsko sunce sekciju) — istražiti vizne zahteve PO ZEMLJI kad se ta zemlja stvarno dodaje, ne unapred za sve odjednom

### Opciona "duboka" destinacijska stranica (sa strane, ne u glavnom toku) — ✅ IZGRAĐENO 2026-08-19

**Status: gotovo i deployovano.** Nova `destination_guides` tabela (isti `(campaign, destination)` oblik kao cene), `DestinationGuide` model, `campaign:seed-destination-guide-rows` (samo za već-pocenjene destinacije — 63 reda skafoldovana i lokalno i na produkciji), GraphQL `destinationGuide` query + `hasGuide` flag, `DestinationGuideModalComponent` (native `<dialog>`, jedna instanca za ceo wizard, sadržaj se učitava tek na klik). 152 backend testa prolazi, verifikovano end-to-end sa pravim Turska podacima pre čišćenja.

**Sledeći korak: pisanje pravog sadržaja.** Prazni redovi postoje (63 destinacije), ali nijedan još nema stvaran itinerar/saveti/slike — checklist za to je docblock na `DestinationGuide` modelu (šta se sastavlja iz postojećih podataka vs. šta je stvarno novo istraživanje po destinaciji).

- Ideja, 2026-08-19: link na kraju postojećeg `ui-info-popover` (kružić kod zemlje/grada) koji otvara bogatiju stranicu/karusel po uzoru na Šri Lanka post (hook → mapa/itinerar → realni troškovi → saveti → slike) — SAMO za onog ko hoće dublje, ne dodaje korak u glavni wizard flow
- Vlasnikov stav: ne sme da uspori/zakomplikuje glavni tok, ali obogaćuje sajt za one koji žele — SLAŽEM SE, dobra granica
- **Podaci — bolje nego što izgleda**: već imamo realnu klimu po mesecu (Open-Meteo), realne cene (hospitality/local_stores meta), kulturne tier-ove (tap_water/dress_code/halal) — isti tip "saveti" sekcije kao u Šri Lanka primeru, samo treba sastaviti u čitljiv tekst, ne izmišljati od nule
- Nijansa: višestanični itinerar (kao Šri Lanka) ima smisla na nivou ZEMLJE (velika/raznolika destinacija), ne po pojedinačnom letovalištu-gradu (Alanija/Marsa Alam nemaju "itinerar", samo su grad) — prvo probati na nivou zemlje
- **Slike — NE sa Booking-a.** Isti rizik kao scraping odluka od 13.7 (tuđ sadržaj, automated means, pretnja affiliate odobrenju). Umesto toga: **Unsplash/Pexels API** — pravi besplatni, licencirani javni izvor namenjen baš za putničke fotografije, isti princip kao WhereNext za cene
- **Statičan sadržaj, ne dinamički template** (dogovoreno 2026-08-19) — cene/podaci se ne menjaju svaki dan, pa Claude periodično (na zahtev, ili pred novi sezonski ciklus) prođe i osveži/upiše po kampanji, isti obrazac kao `vibe_profile`/hospitality (sačuvano jednom, prikazuje se besplatno). NE live-generisanje po pregledu stranice — skuplje, sporije, nepotrebno kad podaci ionako ne variraju iz sata u sat.

### Koeficijent "obrok u smeštaju" po destinaciji — ideja, ČEKA vlasnikova real podaci (2026-09-09)

Trenutno stanje (kod, ne pretpostavka): `SearchSessionQueryCompiler::accommodationNightlyPriceCeiling()`
(~linija 803) za `meal_style='u_smestaju'` + izabran `meal_plan_preference` koristi `foodTotal = 0.0`
— namerno, od 2026-09-02, jer je pravi board-plan premium suviše nepredvidiv iz Booking podataka
(2x-12x razlika za isti grad) da bi se bilo šta pouzdano oduzelo od budžetskog plafona.

**Vlasnikova ideja, 2026-09-09:** sad kad se realno istražuju Hotels.com cene po gradu, moguće je
za svaki grad IMPLICITNO izvesti cenu obroka iz razlike cene sobe sa/bez pansiona (npr. half-board
minus room-only), i uporediti je sa VEĆ POSTOJEĆOM `hospitality`/eating-out procenom za taj isti
grad. Prvi realan primer: Prag — naša `eating_out` procena ~25€/dan, implicirana cena obroka iz
hotelskih cena ~35€/dan → **+40% koeficijent** za taj grad. Eksplicitno rečeno da će se ovo
razlikovati po destinaciji — negde +20%, negde možda i JEFTINIJE nego napolju, ne fiksna globalna
konstanta.

**Šta bi trebalo da postoji kad podaci stignu** (vlasnik lično radi poređenje, per grad, tokom
redovnog Hotels.com price research-a — nije za sad, čeka se realan broj po gradu):
- Nov meta ključ (predlog): `hospitality.meal_at_accommodation_multiplier` po gradu/zemlji (isti
  nivo kao `local_stores`/`hospitality` meta danas) — multiplikator na postojeću eating-out cenu,
  ne apsolutan broj (tako automatski prati ako se eating-out procena ikad revidira).
- `accommodationNightlyPriceCeiling()`'s `0.0` grana za `u_smestaju` postaje `$estimate['eating_out_total_eur'] * ($multiplier ?? 1.0 bez podatka i dalje 0.0 fallback, ne pogađati)` — ako grad nema
  multiplikator, ostaje na sadašnjem bezbednom `0.0` ponašanju, isti "absent, not guessed" obrazac.
- Isti broj hrani (a) info-popup koji već postoji za meal-plan kontekst (destination guide/
  step-description popover) — prikazati "obroci u smeštaju ovde koštaju otprilike X% više/manje
  nego napolju", i (b) samu budžet-fit matematiku gore.
- Vezano, ali NIJE isto: `GeographyResolver::mealPlanFitFor()`/`TaxonomyNode::offersMealPlan()` —
  to je AVAILABILITY signal (da li grad uopšte nudi taj pansion), ovo je CENOVNI koeficijent kad
  nudi. Oba mogu da postoje nezavisno za isti grad.

### GBP/London — rešeno, NE otvarati ponovo

- Vlasnikova odluka 2026-08-19: nema potrebe za multi-currency infrastrukturom. Mi unosimo SVOJU EUR procenu cene (isti ručni proces kao svugde), stvarna transakcija/valuta je Booking-ov problem. London ostaje kao kandidat za Jesenjovanje bez ikakve posebne obrade.
