# Discount Hub

Единый сервис скидок для Магнита, Metro и будущей Ленты.

## Stack

- Backend: Laravel API, PHP 8.3, queues, scheduler
- Frontend: Nuxt 3, Vue 3, TypeScript, Tailwind
- Database: PostgreSQL
- Cache and queues: Redis
- Runtime: Docker Compose for VPS

## Local start

### OpenServer local test

The OpenServer project file is already created at `.osp/project.ini`.

Fastest test run:

```bat
start-local.bat
```

It prepares Laravel, installs frontend dependencies if needed, starts the backend at `http://127.0.0.1:8088`, and starts Nuxt at `http://localhost:3000`.
It also starts a Laravel queue worker window, so admin parser launches return immediately and continue in the background.

To stop local test servers:

```bat
stop-local.bat
```

1. Restart OpenServer or rescan projects.
2. Make sure the `discounts.loc` domain appears in OpenServer.
3. Copy the local Laravel env:

```bash
cp backend/.env.openserver.example backend/.env
```

4. Install backend dependencies and prepare SQLite. On OpenServer Windows you can use the helper script:

```bat
setup-openserver.bat
```

Or run the commands manually:

```bash
cd backend
..\composer-openserver.bat install
..\artisan-openserver.bat key:generate
..\artisan-openserver.bat migrate --seed
```

5. Start the Nuxt frontend:

```bash
cd ../frontend
cp .env.example .env
npm install
npm run dev -- --host 0.0.0.0
```

Open:

- Frontend: `http://localhost:3000`
- API: `http://discounts.loc/api`
- API health: `http://discounts.loc/up`

If OpenServer has not picked up `discounts.loc` yet, restart OpenServer or rescan projects. You can also run the backend without a domain:

```bat
serve-backend-openserver.bat
```

Then set `frontend/.env` to:

```env
NUXT_PUBLIC_API_BASE=http://127.0.0.1:8088/api
```

### Docker/VPS start

```bash
cp backend/.env.example backend/.env
docker compose up -d --build
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan migrate --seed
```

The public UI is served by Nuxt on `http://localhost:3000`. The API is served by Laravel on `http://localhost:8080/api`.

## Existing parser sources

- Magnit legacy code: `../magnit.loc`
- Metro legacy code: `../metro.loc`

The new backend keeps store-specific fetching behind provider classes instead of coupling the UI to parser scripts.

## Parser notes

- Cities are created from stores. Providers pass `city` when the source has it; otherwise the backend tries to extract the city from the store address.
- Metro refreshes the default local store from `METRO_TRADECENTERS_URL` + `METRO_DEFAULT_TRADE_CENTER_ID`; for `METRO_DEFAULT_STORE_ID=54` the default tradecenter id is `55`.
- Metro categories are loaded from the Metro GraphQL category query before products, so product categories use real category names.
- Lenta parser works through the pickup API flow from the web app. The first release supports only pickup stores and pickup assortment.

## Lenta session refresh

When Lenta anti-bot cookies become stale, `catalog/items` starts returning `HTTP 403`. The admin panel now contains a Lenta session block that can:

- save manual values to `backend/.env`
- run a browser-based refresh and rewrite `LENTA_RAW_COOKIE_HEADER`, `qrator_*`, `Utk_*` and related values

### Install the refresh tool

The browser refresh script lives in `tools/lenta-session-refresh`.

```bash
cd tools/lenta-session-refresh
npm install
npx playwright install chromium
```

If Chromium dependencies are missing on Ubuntu, install them once:

```bash
npx playwright install-deps chromium
```

### Manual refresh

From the admin panel use the `Автообновить cookies` button, or run the same flow from CLI:

```bash
cd backend
php artisan lenta:refresh-session
```

For local debugging with a visible browser:

```bash
php artisan lenta:refresh-session --headed
```

### Nightly cron on Ubuntu

Example nightly refresh at `03:15` before the parsing window:

```cron
15 3 * * * cd /var/www/discounts.loc/backend && /usr/bin/php artisan lenta:refresh-session >> /var/www/discounts.loc/backend/storage/logs/lenta-session-refresh.log 2>&1
```

Recommended order for production:

1. Nightly `lenta:refresh-session`
2. Nightly parser run for Lenta after that command finishes
3. Log rotation for `storage/logs/*.log`

If you deploy to a new server, make sure these are installed before enabling the cron job:

- Node.js
- npm
- Playwright package from `tools/lenta-session-refresh`
- Playwright Chromium browser
