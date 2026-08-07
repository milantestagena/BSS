# ENV_SETUP.md — Kako pokrenuti BSS development environment

> Ovaj fajl objašnjava kako da pokreneš, zaustaviš i debug-uješ lokalni dev environment. Prvobitno pisan za Windows-only setup (2026-07-09), **premešteno u WSL2 (2026-07-10)** zbog performansi — Docker bind-mount na Windows-u je bio 100-200x sporiji (5-20s po HTTP zahtevu umesto ~80ms). Istorijske Windows-only napomene su ostavljene na dnu (sekcija 11) jer objašnjavaju odluke koje i dalje važe (npr. zašto port 8000, zašto Filament v3).

---

## 1. Gde je kod

**Kanonsko mesto (jedino mesto koje se edituje):**
```
WSL2 distribucija: Ubuntu-24.04
Putanja unutar WSL2: ~/projects/BSS  (= /home/milanstojadinovic/projects/BSS)
Sa Windows strane:   \\wsl$\Ubuntu-24.04\home\milanstojadinovic\projects\BSS
```

`C:\Projects\BSS` **više ne postoji kao radni kod** — namerno je ispražnjen (samo `MOVED.md` pokazivač) da se izbegne slučajno editovanje zastarele kopije. Ne pravi novi kod tamo.

```
BSS/
├── backend/    Laravel 13 (PHP 8.4) + Lighthouse (GraphQL) + Horizon + Filament v3
├── frontend/   Angular 21 (Signals) + Tailwind CSS
└── CLAUDE.md
```

---

## 2. Otvaranje u VS Code-u

Instaliraj ekstenziju **"WSL"** (Microsoft) ako je nemaš, zatim:

```bash
wsl -d Ubuntu-24.04
cd ~/projects/BSS
code .
```

Ovo otvara VS Code direktno povezan na WSL2 fajl-sistem (Remote-WSL) — puna brzina za editovanje, IntelliSense, terminal.

---

## 3. Pokretanje backend-a (Laravel + Sail)

**Sve komande idu iz WSL2 terminala** (`wsl -d Ubuntu-24.04`, ili integrisani terminal u VS Code kad je otvoren preko Remote-WSL — taj terminal je već "unutra").

```bash
cd ~/projects/BSS/backend
docker compose up -d
```

Podiže 3 kontejnera: `laravel.test` (PHP 8.4, port **8000**), `pgsql` (port 5432), `redis` (port 6379).

```bash
docker compose ps       # provera da sve radi
docker compose down     # gašenje
```

Portovi se automatski prosleđuju ka Windows `localhost` (WSL2 "localhost forwarding") — `http://localhost:8000` radi identično sa Windows i WSL2 strane.

---

## 4. `docker compose` komanda ne postoji ("unknown command")

Ako iz WSL2 dobiješ `docker: unknown command: docker compose`, znači da `docker-compose` CLI plugin nije povezan za tekućeg korisnika (dešava se posle instalacije nove WSL distribucije). Rešenje:

```bash
mkdir -p ~/.docker/cli-plugins
ln -sf "/mnt/c/Program Files/Rancher Desktop/resources/resources/linux/docker-cli-plugins/docker-compose" ~/.docker/cli-plugins/docker-compose
```

(Koristimo Rancher Desktop, ne Docker Desktop — plugin fajl fizički postoji u Rancher instalaciji, samo nije uvek symlink-ovan za svakog korisnika/distribuciju.)

---

## 5. Korisni artisan pozivi

```bash
docker compose exec laravel.test php artisan migrate
docker compose exec laravel.test php artisan tinker
docker compose exec laravel.test php artisan make:filament-user
docker compose exec laravel.test php artisan route:list
docker compose exec laravel.test php artisan optimize:clear
```

`./vendor/bin/sail` skripta i dalje ne radi pod WSL2 Ubuntu iz istih razloga kao pre (proverava OS, baca grešku) — koristi `docker compose exec` direktno kao gore.

---

## 6. Praćenje logova

```bash
docker compose logs -f laravel.test
docker compose logs --tail=50 laravel.test
```

---

## 7. Pristupne tačke

| Šta | URL |
|---|---|
| Laravel app root | http://localhost:8000/ |
| Filament admin panel | http://localhost:8000/admin |
| GraphQL endpoint (Lighthouse) | http://localhost:8000/graphql |
| Frontend (wizard) | http://localhost:4837 |
| PostgreSQL | localhost:5432 (user: `sail`, pass: iz `.env`, db: `laravel`) |
| Redis | localhost:6379 |

**Admin nalog** (lokalni, promeni pre produkcije): `admin@bss.test` / `password`

Admin nalog se gubi svaki put kad se radi `migrate:fresh` (nova baza) — ponovo ga napravi sa komandom iz sekcije 5.

---

## 8. Pokretanje frontend-a (Angular)

```bash
cd ~/projects/BSS/frontend
ng serve --port 4837 --host 0.0.0.0
```

`--host 0.0.0.0` je bitan pod WSL2 (bez toga dev server ume da se veže samo na WSL2-internu adresu i ne bude dostupan sa Windows strane).

**Prvi put na novoj lokaciji:** `node_modules` se NE sme kopirati sa Windows-a (native binarni paketi kao esbuild su OS-specifični). Uvek `rm -rf node_modules && npm install` sveže unutar WSL2.

---

## 9. Tipičan redosled pokretanja (svaki radni dan)

```bash
wsl -d Ubuntu-24.04
cd ~/projects/BSS/backend && docker compose up -d
cd ~/projects/BSS/frontend && ng serve --port 4837 --host 0.0.0.0
```

Otvoriti http://localhost:4837 (frontend) i http://localhost:8000/admin (admin panel po potrebi) — obično radi sa Windows browser-a bez ikakvih dodatnih koraka.

---

## 10. Poznati "gotchas" (WSL2-specifični)

- **`localhost` u frontend kodu → koristi `127.0.0.1`.** WSL2-ovo automatsko port-forwarding ume da veže samo IPv4. Ako frontend kod (ili bilo šta u browseru) pravi zahtev na `http://localhost:8000/...`, browser prvo pokuša IPv6 (`[::1]`), čeka pun timeout (nekoliko sekundi), pa tek onda pada na IPv4 — svaki zahtev deluje "sporo" iako backend odgovara za ~80ms. Rešenje: `frontend/src/app/core/graphql.service.ts` eksplicitno koristi `http://127.0.0.1:8000/graphql`, ne `localhost`. Ako dodaješ nove pozive ka backend-u, koristi isti obrazac.
- **Docker CLI plugin missing** — vidi sekciju 4.
- **WSL default user se može promeniti** — ako Ubuntu prođe kroz interaktivni first-run wizard (može se desiti i slučajno, npr. iz Windows Terminal-a), menja se default korisnik distribucije sa `root` na novokreiranog korisnika. Fajlovi napravljeni kao root (npr. u `/root/...`) postaju nedostupni ("Permission denied") tom novom korisniku. Proveri sa `whoami` unutar `wsl -d Ubuntu-24.04` da vidiš koji je trenutni default; projekat treba da živi pod TIM korisnikovim home-om (`~/projects/BSS`), ne pod `/root`.
- **Rancher Desktop WSL integracija** mora biti eksplicitno uključena za svaku novu distribuciju (nije automatska kao kod Docker Desktop-a): `rdctl api /settings -X PUT -b '{"version":<trenutna verzija>,"WSL":{"integrations":{"<ime-distribucije>":true}}}'`.
- **Stray Windows proces može "ukrasti" 127.0.0.1:PORT.** Ako neki stari `php.exe` (ili bilo šta drugo) ostane da sluša direktno na `127.0.0.1:8000` na Windows-u (npr. zaboravljen `artisan serve` iz starog Windows-only setupa), on ima prioritet nad WSL2/Rancher Desktop-ovim port-forwarding-om za taj tačan IP — dobijaš CORS/404/pogrešne odgovore koji izgledaju kao da dolaze od pravog backend-a, ali su od potpuno drugog procesa. Proveri sa `Get-NetTCPConnection -LocalPort 8000 | Select LocalAddress,OwningProcess` i `Get-Process -Id <pid>` u PowerShell-u; ugasi stranca sa `Stop-Process -Id <pid> -Force`.
- **Ne kopiraj `node_modules`/`vendor` sa Windows-a u WSL2** — `node_modules` pogotovo, zbog native binarnih paketa (esbuild i sl.) kompajliranih za pogrešan OS. Composer `vendor/` je uglavnom čist PHP i preživljava kopiranje, ali `npm install` sveže je uvek bezbednija opcija za oboje.

---

## 11. Istorijske Windows-only napomene (i dalje relevantne odluke, mehanika zastarela)

- **Zašto port 8000, a ne 80:** na ovoj mašini na portu 80 sluša lokalni Apache/Win64 (drugi projekat) — `APP_URL`/`APP_PORT=8000` u `.env` to zaobilazi. Ova odluka ostaje ista i posle WSL2 migracije.
- **Zašto port 4837 za frontend, a ne 4200:** na ovoj mašini na 4200 već radi drugi projekat (`v-racing-dashboard-frontend`). I dalje važi.
- **Filament verzija:** Filament v4/v5 još nemaju zvaničnu podršku za Laravel 13 (`illuminate/contracts` konflikt) — koristi se Filament v3.3.54+. I dalje važi, nezavisno od OS-a.
- **CORS za `/graphql`:** Laravel-ov default CORS `paths` pokriva samo `api/*` — `graphql` je ručno dodat u `backend/config/cors.php`. I dalje važi.
- **Lighthouse query cache** je isključen (`LIGHTHOUSE_QUERY_CACHE_ENABLE=false`) zbog race condition-a sa 4 paralelna `PHP_CLI_SERVER_WORKERS` procesa nad `database` cache store-om. I dalje važi (nezavisno od bind-mount brzine).
- ~~Sporost zahteva 5-20s zbog opcache/bind-mount~~ — **rešeno WSL2 migracijom**, ne ponavljati taj workaround.
