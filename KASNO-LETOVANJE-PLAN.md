# Kasno letovanje — plan kampanje i wizard dizajn

> Sesija 2026-07-29/30. Ako ovaj fajl vidiš u VS Code Exploreru — u pravom si folderu (WSL native, `~/projects/BSS`). Sve iz ove sesije je i u Claude memoriji, ovo je samo čitljiv snapshot za brzu proveru.

---

## ⚠️ Ažuriranje 2026-07-30 — dosta ovoga je već postojalo u kodu (sesija koju nismo znali da imamo)

Otkriveno čitanjem stvarnog koda: **"kasno letovanje" tema već je postojala** kao `termin_category` `kasno_kupanje` ("Još malo sunca"), sa **32 prave destinacije** (ne 3-4), pravom `taxonomy_node_climates` tabelom, i `OpenMeteoClient` servisom — sve iz sesije od 13-14.7 koju Claude Code nije imao zabeleženu nigde. Detalji ispravke u memoriji (`wizard_architecture.md`).

**Izgrađeno i verifikovano 2026-07-30 (radi end-to-end, testirano pravim HTTP pozivima):**
- `SearchSessionQueryCompiler` servis — kompajlira sesiju u `bookingParams` (pravi Booking parametri) + `honestReportSignals` (klima, distanca, cost_emphasis, preference_tags)
- GraphQL polje `compiledSearchQuery(sessionId: ID!): JSON!`
- `climate:import` artisan komanda — povukla PRAVE istorijske podatke sa Open-Meteo za svih 40 lokacija × 12 meseci (480 redova), zamenila SVE `manual_estimate` procene
- `kasno_kupanje` dopunjen sa `window_start`/`window_end`/`recommended_days_from_start`/`honest_report_thresholds` (generički prag mehanizam, admin-proširiv)
- 9 novih testova, puna suita (51 test) prolazi

**Otvorena stavka koju je vlasnik pomenuo, namerno neodrađena večeras:** mogući prelazak sa GraphQL na TALL stack (ideja iz Gemini razgovora, "nije sveto pismo") — zaslužuje poseban, odmoran razgovor, ne odluku usput. `CLAUDE.md` i dalje kaže stack je finalizovan.

---

## 1. Kampanja — brief

**Redosled tematskih ulaza (ažuriran, menja stari Božić-prvi plan):**

1. **Kasno letovanje** ← FOKUS SAD
2. Armistice Day / city tour / eventualno "kasno kupanje" posebno
3. Božić / Nova godina
4. Ski ture

**Ciljna grupa:** ljudi u hladnijim zemljama centralne/severne Evrope koji preko leta nisu stigli ili imali para za more.

**Period:** kraj septembra – oktobar, max početak novembra (20.9–5.11 radni okvir).

**Destinacije prvog talasa:** Malta, Kipar, Tenerife/Kanari — birane jer su potvrđeno tople i u tom periodu.

---

## 2. Nove šematske odluke

**Nov `trip_type` node:** `jesenje_more` ("Jesenje more") — NE reuse `summer_sea`. Razlog: nezavisan implies/excludes lanac, drugačiji copy, drugačiji seed skup destinacija.

**Nove tabele — `wizard_campaigns` + `wizard_campaign_questions`:**

```
wizard_campaigns
  id, key (npr. 'kasno-letovanje'), label, landing_headline,
  preset_answers (jsonb — npr. {"trip_type_id": X, "termin_category": "kasno_leto"}),
  is_active, timestamps

wizard_campaign_questions
  id, wizard_campaign_id (FK), wizard_question_id (FK),
  sort_order (nezavisan od globalnog sort_order-a pitanja),
  timestamps
  unique(wizard_campaign_id, wizard_question_id)
```

Mehanika: `preset_answers` upisuje vrednosti direktno pri startu sesije (bez pitanja). Ista globalna `wizard_questions` (isto `session_field` mapiranje, ništa se ne menja tu) se biraju i redaju PO KAMPANJI kroz pivot tabelu. `wizard_steps` ostaju globalni; prazan korak za tu kampanju se prosto preskače. **Skup pitanja po kampanji nije fiksan na lansiranju — ako nešto fali, dodaje se kasnije (red u pivot tabeli, ne kod).**

**Klimatski podaci — dve serije, ne jedna:**

```
meta.climate: { air_temp_c: [12 vrednosti], sea_temp_c: [12 vrednosti] }
```

Nijedan postojeći izvor (WhereNext/GeoNames/Teleport) ne nosi temperaturu mora — za prvi talas (3-4 destinacije) ide ručno istraženo, ne čeka se pun pipeline.

---

## 3. Konkretna lista wizard pitanja (Kasno letovanje)

1. Broj putnika (group_type) — reuse
2. Termin unutar prozora — "Pokaži mi najbolje u [20.9–5.11]" vs "Znam tačno datume" (novi UX nad postojećim `date_range` input_type)
3. Persona — reuse
4. Grananje po personi — reuse
5. Preference_tag — reuse
6. Budget_tier — **mora se seedovati, blokira lansiranje**
7. Broj soba — pametan default po group_type, pitanje samo kad je dvosmisleno (društvo/grupa)
8. Zemlja/regija — bez posebne sezonske filter-logike, seed skup JESTE filter za prvi talas
9. Grad — reuse
10. **[GATE — login/kredit]** Tip smeštaja + amenities + kvalitet + otkazivanje + meal plan

---

## 4. Booking parametri — puna mapa (ispravljena i verifikovana 2026-07-29)

| Parametar | Status | Napomena |
|---|---|---|
| `checkin`/`checkout` | ✅ | date_from/date_to |
| `guests.number_of_adults/children` | ✅ | |
| `guests.number_of_rooms` | ✅ | uslovno pitanje |
| lokacijski ID (city/region/...) | ⚠️ BLOKIRANO | čeka affiliate API pristup |
| `filters.accommodation_types` | ⚠️ | `tip_smestaja`, čeka prave Booking ID-jeve |
| `filters.price.min/max` | ✅ dizajn | `budget_tier`, treba seed |
| `filters.rating.minimum_review_score`/`stars` | ✅ dizajn, nije nova šema | bilo koji čvor nosi `meta.booking_stars_min` |
| `filters.accommodation_facilities`/`room_facilities` | ✅ dizajn | nov taxonomy type `accommodation_facility`, isti implies/excludes engine |
| `cancellation_type` | ✅ REAL — **top-level, ne pod filters** | vrednosti: `free_cancellation`/`no_refundable` (deprecated) |
| `payment.timing` | REAL, ali **namerno preskačemo** | checkout odluka, ne utiče na izbor smeštaja |
| `meal_plan` | ✅ REAL, verifikovano na dokumentaciji | breakfast_included/half_board/full_board/all_inclusive |
| `filters.dormitories` | 🔗 | vezano za hostel deljena/privatna soba granu |
| `sort.by`/`direction` | — | mi kontrolišemo, ne pitamo korisnika |

Izvor: developers.booking.com/demand/docs/accommodations/filter-sorting (provereno direktno 2026-07-29)

**Pravilo za sva buduća pitanja:** svako wizard pitanje/opcija mora eksplicitno nositi mapu na koje parametre utiče (postojeći `session_field`/`meta.booking_*` obrazac, sad kao tvrdo pravilo, ne preporuka).

---

## 5. Tri "mini engine-a"

1. **Amenities engine** — NIJE nov backend, isti implies/suggests/excludes resolver nad novim `accommodation_facility` tipom. Novo: LIVE reaktivnost unutar istog multi-select polja (danas radi samo preko granice koraka) — **odgurano za posle sutra**.
2. **Location engine** — NIJE nov, postojeći `GeographyResolver::suggested()`, samo prošireni ulazi (budžet/kvalitet/amenities/temperatura) umesto samo tag-overlap.
3. **Vreme/temperatura engine** — GENUINELY NOV. Predlog: `ClimateSuitabilityService`, čita `meta.climate`, poziva se sa dva mesta (filtriranje lokacija + Honest Report caveat generisanje). **Za sutra: dovoljno da meta podaci postoje i da se direktno koriste, pun servis može posle.**

---

## 6. Plan za sutra (dogovoren obim)

**Cilj kraja dana:** vožnja kroz pravi Angular wizard za "Kasno letovanje" + konstruisan Booking-shaped query objekat koji odgovore oslikava (bez živog API poziva — lokacijski ID i dalje blokiran).

**Radimo:**
- `wizard_campaigns`/`wizard_campaign_questions` migracije + osnovni seed
- `jesenje_more`, `budget_tier`, 3-4 destinacije sa `meta.climate` (ručno istraženo)
- Amenities kao obična multi-select (bez live reaktivnosti)
- Kompletan flow kroz pravi frontend, do gate-a

**Svesno odgurano za posle:**
- Amenities live isticanje/brisanje
- Pun `ClimateSuitabilityService`
- Lep admin UI za `wizard_campaigns` (CRUD polish)
