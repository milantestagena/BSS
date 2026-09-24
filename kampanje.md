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

### Marketing pozicioniranje "zašto Hotels.com, ne Booking" — ideja, ČEKA da Hotels stvarno krene (2026-09-14)

Vlasnikova ideja: Booking "izgleda zaglavljeno u 2000" (ružan, nepregledan sidebar/rezultati) —
prilika da se pozicioniramo kao "probaj, lakše ćeš naći šta ti treba" kad Zimsko sunce (ili bilo
koja Hotels.com kampanja) stvarno krene.

**Bitna korekcija (vlasnikova, odmah posle prvog nacrta ove beleške): MI ne pišemo/citiramo nikakve
brojke ("90% parova...") kao svoj copy.** To korisnik SAM vidi kad otvori prvi konkretan hotel na
Hotels.com (oni realno prikazuju "Loved by couples"/highlights po tipu putnika — videli uživo
2026-09-08) — dokaz se desi organski POSLE klika, van naše kontrole/prikaza, ne nešto što mi
prethodno tvrdimo ili kuriramo. Naša poruka ostaje jednostavna ("probaj Hotels.com preko nas, sam
ćeš videti razliku"), bez navođenja konkretnih pozajmljenih statistika — sigurnije i pošten je,
manji rizik da zvuči kao izmišljen/pozajmljen marketing claim. Ako cene ispadnu i nijansu niže od
Booking-a, to je bonus argument, ne glavni. Vlasnikova pretpostavka: ljudi već traže alternative
Booking-u, ovo bi to iskoristilo.

**Status: samo ideja, nije copy posao dok Hotels.com stvarno ne proradi** (čeka se prijava/
odobrenje — vidi [[project_alternate_affiliate_cookie_options]]). Kad se piše prava landing copy
za Zimsko sunce (ili Jesenjovanje ako i ono ide na Hotels), vratiti se na ovu belešku.

**Nadovezujuća ideja, 2026-09-14 — "helper" landing stranica, VAN glavnog wizard flow-a:**
pošto Hotels.com kolačić (7 dana, BILO KOJI booking) hvata čim korisnik klikne na naš link — ne
mora da prođe ceo wizard, ne mora ni da bukira baš ono što smo mu predložili — vredi napraviti
posebnu, minimalnu landing stranicu čiji je jedini posao brz klik-kroz za organski/social
saobraćaj: "Why Hotels.com? Lakše nađeš šta tražiš. Probaj." + link. Legalno čisto (deep-link je
već sankcionisan alat, ovo nije automatizovan klik). **Oprez:** izbeći "često je i jeftinije" dok
nemamo bar par realnih primera — bezbednija, jednako tačna verzija je samo "lakše nađeš šta
tražiš", da ne naruši Honest Report brend sa neodređenom-ali-tehnički-tačnom cenovnom tvrdnjom.
Ne konkuriše wizard flow-u — različita namena (brz social klik naspram prave personalizovane
preporuke). Nije skicirano/građeno, samo ideja za kad Hotels.com stvarno proradi.

### Otvoreno pitanje — REŠENO 2026-09-09

- Vlasnikova arhitekturna odluka ove sesije: **sopstvena kampanja, ne produžetak** `kasno-letovanje`-ove `season_end_date`. Svoj `wizard_campaign` red, svoj `termin_category` (`zimsko_sunce`), svoj landing copy/ruta (`/wintersun`, "sad je zima kod nas, al evo gde je i dalje leto" duh — vidi draft copy u `app.routes.ts`/`WizardSeeder::seedGermanTranslations()`). Razlog isti kao za `kasno_kupanje` samo: potpuno drugi vremenski prozor + potpuno druga geografija zaslužuju sopstvenu temu, ne grananje unutar postojeće.

### "Karibi" region_group — isti mehanizam kao Jesenjovanje, primenjen unazad (2026-09-16)

Vlasnikov catch: prosečan putnik zna "Karibi" kao pojam/vajb, ne zna/ne mari tačnu državu
(Dominikanska Republika vs. neko drugo ostrvce). Kad se `region_group` mehanizam izgradi (vidi
Jesenjovanje sekciju niže), primeniti isto ovde: **Punta Kana + Puerto Plata** (Dominikanska
Republika) i verovatno i **Cancún** (Meksiko, ali karipska obala geografski/vajb-om) svi dobijaju
relaciju ka `region_group` "Karibi" — korisnik bira "Karibi" jednim klikom umesto da mora da zna
tačnu državu. Budući dodaci (Jamajka/Barbados) automatski upadaju u istu grupu čim se dodaju.
NIJE građeno, čeka da se region_group mehanizam prvo izgradi (verovatno kroz Jesenjovanje rad).

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
- **Vlasnikova odluka, 2026-09-16: cilj je ~50 gradova PRE lansiranja (ne fazni talasi kao kasno-letovanje), "kolko potraje - potraje"** — ne žuri se, ide se na širinu odmah.

### Persone za city_break — SAMO 3, ne sve 4 (odlučeno 2026-09-16)

`Flegma` (Chillseeker) EKSPLICITNO isključena iz `city_break` trip_type-a — vlasnikov catch: Flegma-in
sopstveni opis ("odmor treba da bude odmor... ležaljka, piće, plaža") je suštinski suprotan city
break konceptu (aktivno istraživanje). Neko ko hoće da samo leži bira plažu (kasno-letovanje/Zimsko
sunce), ne grad. Implementacija: `$this->relate('trip_type', 'city_break', 'excludes', 'persona', 'flegma')`,
isti obrazac kao postojeće `city_break excludes termin_category letovanje/zimovanje`. Spa/kafe-kultura
gradovi (Beč, Budimpešta terme, Bukurešt Therme) i dalje mogu da pomenu to u vibe_profile-u kao opšti
highlight, samo više nije vezano za personu.

Ostaju: **Istraživač, Partijaner, Gurman.**

### Kandidati istraženi 2026-09-16 (real WebSearch, ne nagađano)

**Prvi talas (visoka pouzdanost — potvrđena tražnja I/ILI potvrđena sezonalnost):**
- **Prag, Budimpešta** — POTVRĐENO da su specifično jesen/zima peak destinacije za Nemce (ne samo
  "klasici" — pravi sezonski match za Jesenjovanje). Partijaner (ruin bars) + Istraživač. Budimpešta
  dodatno ima terme (opšti highlight, ne persona-vezan posle Flegma odluke).
- **Beč** — konstantno visoko rangiran, DACH sused. Istraživač + Gurman.
- **Barselona** — trend grad (potvrđeno 2026-09-08). Gurman + Partijaner + Istraživač.
- **Amsterdam** — konstantno visoko. Partijaner + Istraživač.
- **Rim** — VEĆ POSTOJI kao taxonomy node (bio isključen iz swim kampanje namerno). Istraživač + Gurman.
- **Bukurešt** — real potvrđeni direktni letovi (Frankfurt 20x/nedeljno Lufthansa + 8x TAROM, plus
  Minhen/Diseldorf/Štutgart). Jedinstven hook: **Bran Castle "Dracula's Castle"/"Schloss Bran"**
  (~1 milion posetilaca/god, dnevni izlet, nema direktnih letova do Brašova pa se ide iz Bukurešta)
  + **Therme Bucuresti** (opšti highlight). Istraživač (dvorac) + Gurman.

**Balkan gurmanski klaster (vlasnikova ideja, potvrđena logistika, ALI neizvesna DACH-specifična
tražnja — posebna napomena ispod):**
- **Beograd** — najjača logistika u klasteru: Beč 36 letova/nedeljno(!), direktno Frankfurt/Minhen (Lufthansa).
- **Sarajevo** — potvrđen direktan Frankfurt + Austrian Airlines mreža.
- **Novi Sad** — NEMA aerodrom (vlasnikova sopstvena napomena) — dnevni izlet iz Beograda, isti model
  kao Brašov/Bukurešt, ne samostalna baza.
- **Niš, Skopje** — imaju aerodrome, low-cost prisustvo (Air Serbia, Wizz Air) — TREBA proveriti
  tačnu oktobar-frekvenciju pre uključivanja, ne samo da aerodrom postoji.
- **Ohrid** — ima aerodrom, verovatno sezonski/leto-fokusiran — proveriti da li uopšte radi u oktobru.
- **Napomena o tražnji (bitna, nerešena):** agregatni podaci kažu "Germany highest foreign
  overnight-stay volume in Serbia, July 2026" i "Serbia popular AUTUMN destination for German
  tourists" — ALI vlasnikovo lično zapažanje sa terena (živi u regionu) je da praktično ne viđa
  Nemce uživo, dok Skandinavce/Amerikance da. Moguće objašnjenje: agregatni brojevi mešaju pravi
  turizam sa POSETOM DIJASPORE (bivša Jugoslavija u Nemačkoj/Austriji) koja ne treba nikakvu
  reklamu da sazna za Srbiju — nije naša ciljna grupa. Nije razrešeno, samo obe strane priče
  zapisane. Gurman persona i dalje relevantan (hrana je realno autentična), ali ne trošiti prvi
  real budžet ovde dok se ne vidi kako konvertuje.

### Princip za "topliji region" gradove (dogovoreno 2026-09-16)

Ne markirati kao "plaža" — to je Zimsko sunce/kasno-letovanje posao, plaža se traži zimi/letom, ne
za oktobarski city break. Umesto toga, iz istih toplijih zemalja birati gradove sa jasnim
kulturnim/party/gastro identitetom, ne kupanjem:

- **Italija**: Milano, Firenca, Venecija (već pomenuta "stalno visoko") — Istraživač + Gurman.
- **Malta**: **Valeta** (glavni grad, UNESCO, NIJE trenutni swim-kampanje grad) + reuse postojeće
  **Sliema/St Julian's** (već postoje kao taxonomy nodes iz kasno-letovanje, dodati i u city_break).
  Prvobitan WebSearch nalaz (generički travel blog) je rekao "party season vrhunac jun-avgust,
  jesen samo solidna alternativa" — **ISPRAVLJENO vlasnikovim ličnim iskustvom (živeo je tamo,
  jače od bilo kog bloga)**: pravi obrazac je NEDELJNI ciklus, cele godine — studenti stižu
  četvrtak uveče, pune slobodne sobe u ulici, nedelja popodne ih kombi-taksi vozi na aerodrom,
  svake nedelje. Nije sezonski fenomen ograničen na leto, nego stalan, ponavljajući weekend-trip
  obrazac — jak, pouzdan celogodišnji Partijaner fit, ne "solidna alternativa van sezone". Isti
  princip kao CJ Program Terms > blogovi svuda drugde u ovom projektu — živo iskustvo pobeđuje.
- **Španija**: Valensija, Barselona (već imali), Sevilja — Gurman + Partijaner + Istraživač,
  namerno bez plažnog pozicioniranja.
- **Francuska** (Claude-ov predlog 2026-09-16, vlasnik "nije u toku"):
  - **Lion (Lyon)** — realna gastro-prestonica, rivalizuje sa Parizom po hrani, manje turistički
    pretrpan. Gurman.
  - **Strazbur** — čuvene božićne pijace (kraj novembra — dobar most ka budućoj zimskoj temi),
    pola-drveni stari grad, direktno na granici sa Nemačkom. Istraživač.
  - **Bordo** — vino kultura. Gurman.

### Porodice sa decom — reuse postojećeg `porodicna_atmosfera` tag-a, NE nova logika (2026-09-16)

Vlasnikov ask: šta nudimo porodicama sa malom decom, ima li Prater-like (Beč) atrakcija koje su
manje mejnstrim od Diznilenda? Real WebSearch nalaz — Evropski rang po posećenosti (posle
Diznilend Pariza): **#2 Europa-Park (Rust, Nemačka — DOMAĆA destinacija, ne uklapa se u "putuj u
inostranstvo" model, samo pomenuto radi kompletnosti)**, **#3 Efteling (Holandija)**, **#4 Tivoli
Gardens (Kopenhagen, od 1843, istorijski/šarmantan)**. Plus **Gardaland** (jezero Garda, blizu
Verone) kao jak regionalni izbor.

- **Tivoli** → highlight za **Kopenhagen** (već na listi) — ne novi grad.
- **Gardaland** → highlight za **Veronu** (već na listi kao Romeo/Julija hook) — dupli razlog za taj grad.
- **Efteling** → treba NOV grad: **'s-Hertogenbosch (Den Bosch)** ili Tilburg (~20-30min vožnje do parka).
- **Bukurešt Therme** — vlasnikov catch, već pokriveno gore, "vrh" za porodice.
- **Mehanizam**: NE treba nova logika — `porodicna_atmosfera` preference tag VEĆ POSTOJI (iz
  letovanje kampanje, property-level Booking filter + city/country meta match) i tačno pokriva ovaj
  signal nezavisno od persone. Ovi gradovi se samo taguju tim postojećim tagom za city_break, isti
  obrazac, ne izmišljati novi mehanizam.

### DACH SAM kao destinacija, ne samo publika (vlasnikov catch, 2026-09-16 — ispravio moju grešku)

Prvobitno sam Europa-Park (Rust, Nemačka) izostavio kao "domaću destinaciju, ne uklapa se u
putuj-u-inostranstvo model" — POGREŠNO. Naša PUBLIKA je DACH, ne znači da destinacija mora biti
van DACH-a — neko iz Berlina i dalje leti/vozi do Minhena, prava je rezervacija. Ovo takođe pomaže
da se realno stigne do ~50 gradova (vlasnikov cilj).

- **Oktoberfest (Minhen), 2026: 19. sept - 4. okt (potvrđeno)** — NEODLUČENO da li se uklapa u
  Herbstferien prozor (najraniji, Hessen/RLP/Saarland, kreće tek 5.10 — Oktoberfest se ZAVRŠAVA
  pre toga). Parkirano za sad (vlasnikov ask, 2026-09-16: "ako je prerano, preskoci"), grad
  Minhen se svejedno dodaje, samo Oktoberfest-specifičan sadržaj/timing čeka odluku.
- **Švajcarska, real oktobar nalaz (WebSearch)**: oktobar je stvarna niska sezona (manje gužve,
  10-15°C). **Lucern** — "najsigurniji" izbor, Rigi/Pilatus/Stanserhorn planine OTVORENE cele
  oktobra. **St. Moritz/Engadin dolina** — najbolji jesenji "zlatni" pejzaž u Švajcarskoj (ariš
  šume, sred-kraj oktobra), skuplji ali jedinstven hook, ime se savršeno uklapa u "Jesenjovanje".
  **Zermatt NAMERNO IZOSTAVLJEN** — mnoge atrakcije/restorani se zatvaraju već prvom nedeljom
  oktobra, rizik da putnik stigne na zatvoreno. **Interlaken** — mešovito (Jungfraujoch radi cele
  godine, ostalo "se gasi") — može ući sa napomenom.
- **Dodatni DACH batch (Nemačka)**: **Nirnberg** (Christkindlesmarkt — jedan od najpoznatijih
  božićnih vašara na svetu, dobar za kasni-novembarski deo sezone), **Rotenburg na Tauberu**
  (bajkoviti srednjovekovni gradić, godišnji božićni šarm), **Hajdelberg** (romantična starina +
  zamak), **Drezden** (barok, umetnost), **Keln** (katedrala, Rajna, sopstveni božićni vašar),
  **Hamburg** (trendi, luka, noćni život), **Minhen** (Oktoberfest, gore).
- **Austrija (dodatno uz Beč/Salcburg)**: **Insbruk** (Alpe, pravi grad ne samo ski-resort),
  **Grac** (drugi grad, UNESCO stari grad, manje turistički).
- **Švajcarska (dodatno uz Cirih/Ženevu)**: **Lucern**, **St. Moritz**, **Bern** (glavni grad,
  UNESCO), **Bazel** (umetnost, tromeđa DE-FR-CH), **Interlaken** (sa napomenom gore).

### Engleska — ne samo London (vlasnikov catch, 2026-09-16)

Real WebSearch potvrda: **Edinburg i Mančester eksplicitno navedeni u top 5 gradova za NEMAČKE
posetioce** (uz London i Bath). Sva četiri dele istu Schengen/pasoš napomenu kao London (nije
razlikovni faktor među njima, samo prema kontinentalnoj Evropi).

- **Edinburg** — potvrđeno jak za nemačke turiste. Istraživač (zamak, istorija, festival grad).
- **Mančester** — potvrđeno jak za nemačke turiste, PLUS sopstveni božićni vašar sa "German-inspired"
  štandovima — dobar sezonski hook. Partijaner.
- **Liverpul** — najbolje ocenjen UK grad za odmor uopšte (bolji od Mančestera po kulturi/
  smeštaju/gužvi), ALI bez eksplicitne nemačke potvrde poput druga dva. Partijaner + Gurman
  (Bitlsi, muzička scena).
- **Njukasl** — realan, ali NAJSLABIJI od ova četiri (7. od 10 u UK top listi za kratak odmor,
  bez nemačke potvrde) — uključen, ali sa nižim poverenjem.
- Mančester/Liverpul/Njukasl grupisani pod šaljivim radnim imenom **"Fudbalska podtura"**
  (vlasnikova ideja) — realan mogući content hook kasnije (stadion posete, navijačka kultura).
- **Cela "Which?" top 10 UK gradova lista** (opšta short-break ocena, RAZLIČIT izvor od
  nemačko-specifičnog top 5 gore, ne mešati): 1. Liverpul, 2. Edinburg, 3. **Jork**, 4. **Belfast**,
  5. **Glazgov**, 6. London, 7. Njukasl, 8. **Portsmut**, 9. **Bristol**, 10. Kardif. Novo odavde:
  **Jork** (istorijski zidovi, Minster katedrala, Viking nasleđe) — Istraživač. **Belfast**
  (Titanik četvrt, Game of Thrones lokacije) — Istraživač. **Glazgov** (muzička scena, drugačiji
  vajb od Edinburga) — Partijaner. **Bristol** (street art/Banksy, muzika) — Partijaner+Istraživač.
  **Portsmut** (pomorska istorija) — nišniji, niže poverenje.
- **Bat** — potvrđeno (ista pretraga, top 5 za dolazne UK turiste), rimske terme + đorđijanska
  arhitektura.
- **Kardif** — real, kompaktan (zamak+muzej blizu), bez specifične nemačke potvrde — uključen sa
  nižim poverenjem, isto kao Liverpul/Njukasl.
- **Devon regija — NIJE grad**, i njen glavni turistički centar (Torki/"English Riviera") je čist
  PLAŽNI resort (peščane plaže, Blue Flag nagrade) — kosi se sa "nema plaže" principom ove
  kampanje. Umesto toga: **Ekseter** — pravi grad regije, gotska katedrala, srednjovekovna/
  đorđijanska arhitektura, bez plažnog identiteta.

### Drugi batch — pomenuti u chat-u 2026-09-16 ali NIKAD upisani (ispravljeno, vlasnikov catch: "dodaj sve koji fale")

Nivo pouzdanosti mešovit — neki potvrđeni ranijim WebSearch-om (2026-09-08), neki samo opšte
znanje (isto obeleženo, treba re-proveriti pre gradnje, ista disciplina kao Prag/Budimpešta/Krakov
napomena gore):

- **London** — potvrđena visoka tražnja (2026-09-08 pretraga: "Barselona, pa Pariz, pa London").
  Non-Schengen napomena i dalje važi (pasoš, valuta). Istraživač + Gurman.
- **Pariz** — isto potvrđeno visoko. Gurman + Istraživač.
- **Brisel** — opšte znanje, nije sveže potvrđeno. Grand Place, čokolada/pivo kultura. Gurman + Istraživač.
- **Napulj** — opšte znanje. Rodno mesto pice, real Gurman hook, Pompeji kao dnevni izlet — namerno
  fokus na stari grad/hranu, ne na Amalfi obalu (plažno, van principa ove kampanje).
- **Lisabon, Porto** — opšte znanje ali mejnstrim popularnost. Fado/tramvaji/hrana (Lisabon), luka/
  vino (Porto, manje turistički od Lisabona). Gurman + Partijaner + Istraživač.
- **Madrid** — opšte znanje, glavni grad. Gurman + Istraživač.
- **Solun** — grčki "gurmanski" drugi grad (real reputacija, manje turistički od Atine). Gurman.
- **Krakov** — NIJE sveže potvrđeno (već obeleženo gore), jeftin/istorijski klasik. Istraživač.
- **Varšava** — opšte znanje, moderno+istorijski mix, slabija "šarm" reputacija od Krakova.
- **Bratislava** — mali, jeftin, blizu Beča (moguć kombo/dnevni izlet) — nižeg obima ali real.
- **Ljubljana** — rastući "hidden gem" trend (potvrđen ranijom pretragom o "quieter alternatives").
  Istraživač.
- **Zagreb** — **real jak hook**: Zagreb Advent (božićni vašar) je VIŠE PUTA proglašavan
  najboljim božićnim vašarom u Evropi (European Best Destinations nagrada) — jak novembar-fokus.
  Istraživač.
- **Brugge** — već pomenut kao "jedinstven hook" (čokolada, srednjovekovni kanali).
- **Stokholm** — opšte znanje, skuplji, Nordic vajb. Istraživač.

### Francuski Atlantik (vlasnikov ask, 2026-09-16)

- **Nant** — čist gradski identitet (Dvorac vojvoda od Bretanje, "Machines de l'île", industrijske
  četvrti, mlada populacija), NIJE plažni. Istraživač.
- **La Rošel** — real i šarmantna, ALI opis (peščane plaže, ostrva) je previše plažno-obojen za
  ovu kampanju — ISTA tenzija kao Torki/Devon, namerno izostavljena iz istog principa.

---

## Arhitektura — ideje koje važe za VIŠE kampanja (ne specifično za jednu)

Dogovoreno 2026-08-19. **Prioritet: tag on/off sistem, radimo uskoro** (ne "kad-tad", stavka za sledeću sesiju odmah).

### "Oblast" — novi taksonomski nivo između zemlje i grada (vlasnikov ask, 2026-09-16 — MOŽDA PRVI TASK sledeće sesije)

Vlasnikov catch, tokom Jesenjovanje diskusije ali se odnosi pre svega na LETOVANJE/Zimsko sunce
kampanje: neke regije imaju sopstveni jak identitet koji je ŠIRI od jednog grada, i trenutna
taksonomija (country → city) nema mesta za to.

**Konkretni primeri koje je vlasnik naveo:**
- **Sitonija** (poluostrvo u Halkidikiju, Grčka) — cela oblast ima svoj vibe, ne jedan grad/mesto
  unutra (Sarti, Vurvuru, Neos Marmaras, itd. — trenutno bi svaki morao da bude poseban city node).
- **Epirska obala** (zapadna Grčka) — manje poznata, ali realna regija sa sopstvenim identitetom.
- **Azurna obala (Côte d'Azur)** — "ne mora samo St Tropez" — cela obala (Nica, Kan, Antib,
  Monako, Manton...) ima jedinstven brend/identitet koji nadilazi bilo koji pojedinačni grad.

**Šta ovo znači arhitektonski (NIJE razrađeno, samo zapisano da se ne zaboravi):**
- Novi tip node-a između `country` i `city` — možda `region` tip, roditelj gradovima unutar te
  oblasti, ali i sam biva ODABIRAN/PRIKAZAN korisniku kao svoja opcija (ne samo grupisanje).
  Trenutni `country`/`city` tipovi + `parent_id` hijerarhija VEROVATNO već tehnički podržavaju
  treći nivo bez šeme promene (dodati `type='region'` node sa `parent_id` = zemlja, pa gradove sa
  `parent_id` = ta regija) — ali treba proveriti da GeographyResolver-ova logika (koja danas misli
  u terminima "country" i "city" eksplicitno po tipu stringa) ne pukne na trećem nivou pre nego
  što se gradi.
- Pitanje: da li korisnik BIRA oblast kao svoju destinaciju direktno (kao alternativa gradu), ili
  oblast samo grupiše/organizuje gradove ispod sebe a korisnik i dalje bira konkretan grad? Vlasnik
  nije precizirao — vredi razjasniti pre gradnje.
- Cene/klima/kulturni podaci bi verovatno i dalje živeli na CITY nivou (kao i sad), oblast bi bila
  pre svega organizaciona/prikazna, ne nosilac sopstvenih podataka — ali ni ovo nije odlučeno.

**Status: SAMO zapisano, ništa odlučeno niti građeno.** Vlasnikova napomena: "možda nam to bude
prvi task" [sledeće sesije] — visok prioritet za RAZGOVOR, ne odmah za kod.

### "Region group" — DRUGI, različit koncept od "Oblast" gore — ODLUČENA arhitektura (2026-09-16)

Ne mešati sa "Oblast" iznad (koja je DEO jedne zemlje, npr. Sitonija unutar Grčke). Ovo je grupa
PREKO više zemalja (Balkan, Benelux, Skandinavija, British Isles, SouthWest EU, Atlantic...), i to
tako da može da uzme SAMO DEO jedne zemlje — npr. Francuska se cepa: Lion/Valensija idu u
"SouthWest EU", Nant ide u "Atlantic", ne cela zemlja u jedan koš.

**Zašto ne postojeći `region_theme` (mediteran, dalje_sunce)**: taj mehanizam radi na nivou CELE
ZEMLJE (zemlja → region_theme, `parent_id` hijerarhija) — ne može da pocepa jednu zemlju na dva
regiona, pošto zemlja ima samo JEDAN `parent_id`.

**Dogovorena arhitektura (additivna, ne redizajn):**
- Nov tip node-a: `type='region_group'` (npr. slug `balkan-autumn`, label "Balkan") — isti `node()`
  helper kao sve ostalo.
- Gradovi/zemlje se kače preko VEĆ POSTOJEĆE `taxonomy_node_relations` tabele (isti mehanizam kao
  implies/excludes/suggests), NOV tip relacije → region_group. Grad/zemlja ZADRŽAVA svoj pravi
  `parent_id` (stvarna hijerarhija, klima/kultura/prevodi i dalje rade normalno) — region_group je
  DODATNA, paralelna veza, ne zamena.
- **Optimizacija (vlasnikov ask, 2026-09-16): relacija može da ide na NIVOU ZEMLJE, ne samo grada** —
  isti mehanizam, primenjen jedan nivo više. Dva slučaja:
  - **Ceo lakši slučaj** (većina zemalja — Srbija, BiH, S. Makedonija, Belgija, Holandija, UK,
    Poljska, Slovačka, Slovenija, Hrvatska, Rumunija, Španija): relacija zemlja→region_group,
    JEDAN red. Svaki NOVI grad dodat toj zemlji kasnije automatski nasleđuje članstvo (query ga
    povuče preko zemlje), bez dodatnog rada po gradu.
  - **Cepani slučaj** (Francuska): zemlja SAMA ne dobija nikakvu region_group relaciju, ali
    POJEDINAČNI gradovi (Lion→SouthWest, Nant→Atlantic) dobijaju relaciju direktno. Ostali francuski
    gradovi (Pariz, Strazbur, Bordo) ostaju bez region_group veze, normalno se pojavljuju pod
    "Francuska".
- Wizard "Country/region" korak: opcije postaju `type=country` UNION `type=region_group` —
  korisnik bira "Balkan" kao JEDNU opciju umesto tri zemlje pojedinačno (vlasnikov ask: "u listi
  drzava ubaci i ovo").
- `GeographyResolver` razrešavanje kad je izabran `region_group`: UNION od (a) svih gradova čije
  ZEMLJE imaju direktnu relaciju ka njemu, i (b) svih gradova koji SAMI imaju direktnu relaciju ka
  njemu — jedan mehanizam (taxonomy_node_relations), primenjen na dva nivoa, bez nove tabele.

Jedan grad može da bude u VIŠE region_group nodova istovremeno (npr. teoretski i pogranični grad
koji ima smisla i za dva susedna regiona) — ovo VEĆ radi prirodno sa many-to-many
`taxonomy_node_relations` mehanizmom, bez ikakve dodatne izmene dizajna (za razliku od `parent_id`
koji dozvoljava samo jednog roditelja).

**Predlog makroregija za Jesenjovanje (2026-09-16, delimično potvrđeno):**

| Makroregija | Nivo relacije | Zemlje/gradovi |
|---|---|---|
| Balkan | zemlja | Srbija, BiH, S. Makedonija |
| Benelux | zemlja | Belgija, Holandija |
| Skandinavija | zemlja | Danska, Švedska |
| British Isles | zemlja | UK (svih 13 gradova) |
| Srednja Evropa | zemlja | Poljska, Slovačka, Slovenija, Hrvatska, **Rumunija** (potvrđeno 2026-09-16) |
| SouthWest EU | zemlja (Španija) + grad (Lion) | Španija (cela) + Lion iz Francuske |
| Atlantic | grad + (zemlja?) | Nant iz Francuske + Portugalija (OTVORENO pitanje — cela zemlja ili ne?) |
| **Mediteran** | zemlja (Malta) + gradovi | **Malta cela** (Valeta, Sliema, St Julian's) + pojedinačno: Napulj, Venecija (Italija — obalski gradovi, NE Firenca/Milano/Verona koji su kontinentalni), Barselona, Valensija (Španija — NE Madrid/Sevilja), Atina, Solun (Grčka) |

**Namerno BEZ makroregije** (dovoljno poznate pojedinačno, ne treba grupisanje): Nemačka,
Švajcarska, Austrija, Italija, Grčka, Češka, Mađarska, Malta, ostatak Francuske (Pariz, Strazbur, Bordo).

**Otvoreno**: (1) da li Portugalija (cela zemlja) ide u "Atlantic" sa Nantom, ili treba svoju
grupu — nije odlučeno. (2) "SouthWest EU" ime — Lion geografski nije jugozapad Francuske, ako je
prava logika nešto drugo (vajb/klima/avio-rute, ne kompas), treba pravilo da se dosledno primeni
i na ostatak, ili samo prihvatiti ime kao radni naziv bez doslovne geografske tačnosti.

**VAŽNO OTKRIĆE 2026-09-16 (menja plan iznad — pročitati PRE bilo kakvog koda):**

`region_theme` mehanizam VEĆ POSTOJI, aktivan je (pravo wizard pitanje "Which part of the world
interests you?", `wizard.ts` linije 976-1515 skrolauju `region_theme → country_region → city`
lanac), i to je TAČNO ono što je "region_group" trebalo da bude — ne treba nov tip node-a niti
novi wizard step, samo reuse ovoga. Postojeći node-ovi: `istocna_evropa` (Srbija→Beograd,
Češka→Prag), `zapadna_evropa` (Belgija→Brugge), `mediteran` (Egipat/Kipar/Malta/Tunis/Španija/
Turska/Portugalija/Zelenortska), `dalje_sunce` (Meksiko/Dominikanska Republika), `anticki_svet`
(Italija SA SVIM pravim letovanje gradovima + Grčka SA SVIM pravim letovanje gradovima).

**Poreklo**: `istocna_evropa`/`zapadna_evropa`/`anticki_svet` postoje od PRVOG commit-a projekta
("Initial commit") — stari demo/placeholder podaci iz najranije faze. Beograd/Prag/Brugge imaju
samo `{lat,lng}` meta — bez klime/prevoda/ičega realnog, bezbedno za čišćenje/preradu.

**OPASNOST — anticki_svet NIJE mrtav, strukturno je živ:** `kasno_kupanje` (kasno-letovanje)
termin_category EXCLUDES `istocna_evropa`+`zapadna_evropa`, ali NE excludes `anticki_svet` — znači
Italija i Grčka (sa SVIM pravim letovanje gradovima, Sicilija/Sardinija/grčka ostrva) i dalje imaju
`anticki_svet` kao svoj `parent_id`. Brisanje/menjanje tog node-a bez opreza bi osirotelo te dve
zemlje (slomljen parent_id) — NE dirati neoprezno.

**Vlasnikov predlog za anticki_svet neusklađenost (2026-09-16)**: "Ancient world" kao tema je
zapravo bliža nekoj BUDUĆOJ prolećnoj/istraživačkoj kampanji nego kasnom letovanju — "zalutalo je
tu". Jeftin fix: samo PROMENITI LABEL tog node-a (bez diranja parent_id/dece) da bolje odgovara
stvarnoj upotrebi (Italija+Grčka za letovanje) — ne treba migracija podataka, samo tekst. Pažnja:
ne zvati ga "Mediteran" bukvalno pošto taj slug/label VEĆ POSTOJI kao poseban node — izbeći
duplikat/konfuziju, smisliti drugačiji, tačan naziv.

**Preostali pravi posao (za sledeću sesiju, sveže, ne na kraju duge sesije):**
1. Očistiti/preraditi Beograd/Prag/Brugge (stari placeholder) — ili ih obrisati ako se ne koriste
   nigde uživo, ili ih upisati kao prave prve Jesenjovanje gradove pod ispravnim region_theme-ovima.
2. Preimenovati `anticki_svet` label na nešto tačnije (vlasnikov predlog gore) — bezbedna, izolovana izmena.
3. Sazidati NOVE region_theme node-ove za makroregije iz tabele gore (Balkan, Benelux, Skandinavija,
   British Isles, Srednja Evropa, SouthWest EU, Atlantic, Mediteran-za-city-break — OPREZ, ne
   mešati ime sa postojećim `mediteran` node-om, treba svoj slug čak i ako je ista tema).
4. Za "cepani" slučaj (Francuska: Lion→SouthWest, Nant→Atlantic) — pošto zemlja ima samo JEDAN
   `parent_id`, treba DODATNI mehanizam: grad direktno relacija ka region_theme preko
   `taxonomy_node_relations` (nov tip relacije), GeographyResolver čita UNION oba puta (zemljin
   parent_id ILI grad-direktna relacija). Ovo JOŠ NIJE proverено da li GeographyResolver-ova
   `country_region` scoping logika (linija ~977 u wizard.ts, `loadGeography('country_region',
   'country', value)`) uopšte podržava takav union bez izmene — proveriti pre gradnje.
5. Tek onda: mikroregija (Oblast) mehanizam — potpuno odvojen, sledeći korak posle ovoga.
6. Realni gradovi (73 na listi) i dalje NE POSTOJE kao TaxonomyNode zapisi (osim Rim/Atina) — to
   je odvojen, mnogo veći posao (klima, prevodi, vibe_profile, cene) koji dolazi POSLE arhitekture,
   ne pre nje.

**Status: arhitektura ODLUČENA (uz gornju korekciju), kod NIJE pisan** — čeka sledeću, svežu sesiju.

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

**ISPRAVKA 2026-09-14 — ovo VEĆ POSTOJI, skoro identično, otkriveno slučajno kroz admin nav
("Meal Plan Coefficient" tab zbunio i vlasnika i Claude-a dok se nije proverio git log):**
`MealPlanCoefficientCalculator` Filament stranica (`app/Filament/Pages/`, commit `23ac2d5`,
vlasnikov ask 2026-08-13, dakle STARIJE od ove beleške) već radi TAČNO ovo — unese se jedan
hotelov room-only vs all-inclusive cena (Booking), plus poznata avg-restaurant-meal cena za tu
zemlju, i izračuna se `meta.meal_plan_coefficient` (1.0 = naplaćuje tačno restoransku cenu, <1 =
popust, >1 = "naplata lenjosti") — čita ga `BudgetEstimationEngine::mealPlanTotalFor()` (default
0.8 dok se realno ne izmeri po zemlji). Jedina prava razlika od ove beleške: postojeći koeficijent
je PO ZEMLJI (ne po gradu) i hrani `mealPlanTotalFor()`/`fitFor()` (budžet-fit PROCENA), ne
`accommodationNightlyPriceCeiling()` (Booking link cenovni plafon) — to drugo mesto i dalje ima
svoj poseban `0.0` za `u_smestaju`, NIJE automatski povezano sa `meta.meal_plan_coefficient`.

**Preostali pravi posao (mnogo manji nego što je gornji predlog sugerisao):** kad se realno izmeri
`meal_plan_coefficient` po zemlji (kroz postojeći kalkulator, Booking ili Hotels.com podaci — svejedno
kom sajtu, princip isti), samo iskoristiti TU vrednost i u `accommodationNightlyPriceCeiling()`'s
`u_smestaju` grani (`$estimate['eating_out_total_eur'] * $country->meta['meal_plan_coefficient']`,
fallback ostaje `0.0` bez izmerene vrednosti) — nema potrebe za novim meta ključem niti novim
alatom, samo povezati dve već postojeće stvari. Ne raditi ovo dok se realno ne izmeri bar par
zemalja — trenutni default 0.8 nije dovoljno pouzdan da se ceiling matematika osloni na njega.

### GBP/London — rešeno, NE otvarati ponovo

- Vlasnikova odluka 2026-08-19: nema potrebe za multi-currency infrastrukturom. Mi unosimo SVOJU EUR procenu cene (isti ručni proces kao svugde), stvarna transakcija/valuta je Booking-ov problem. London ostaje kao kandidat za Jesenjovanje bez ikakve posebne obrade.
