# Saanj Garva · सांज गारवा — Bar Management PWA

Single-bar stock, sales, udhari (khata) and profit app. Marathi + English, mobile-first, installable.

```
frontend/   React 19 + Vite + Tailwind v4 PWA  → deploy to Vercel
backend/    Laravel 12 REST API (Sanctum tokens) + PostgreSQL → deploy to any PHP host
```

## Features

| Area | What it does |
|---|---|
| Dashboard | Today's sales, cash / udhari / other, expenses, gross & net profit, low/out-of-stock, outstanding udhari, 7-day chart, quick actions |
| Sales (POS) | Search/category tiles → tap to add → Cash / Udhari / Other → complete. Shop stock is validated and deducted atomically. Bills can be viewed, printed and cancelled (stock + udhari reversed) |
| Store stock | Godown stock, add stock (purchase), value, low/out filters |
| Store → Shop transfer | Never allows more than Store has; confirmation + full history |
| Shop stock | Selling stock; only changes via transfer, sale, cancelled sale or adjustment |
| Adjustments | Physical count correction with reason (broken, missing…), logged |
| Stock history | Every movement per product/location with running balance |
| Products & categories | Marathi names, units (bottle/can/case/box/piece/peg), size, min stock, cost/selling price, live profit & margin |
| Customers / Udhari | Opening balance, udhari sales, receive payment (cannot exceed outstanding), ledger with date filter, print/PDF, WhatsApp reminder |
| Expenses | Categories (Marathi), date-wise/category-wise totals; included in P&L |
| Reports | Sales (day/week/month + top products), Profit & Loss, Stock, Udhari, Expenses — print/PDF and CSV export |
| Settings | Bar name (EN/MR), logo upload, currency, default low-stock level, CSV backups, change password |

## Business rules (implemented in `backend/app/Services`)

- **Two locations.** `store_stock` and `shop_stock` hold current balances; `stock_transactions` is an append-only ledger
  (`opening`, `purchase`, `transfer_out`, `transfer_in`, `sale`, `sale_void`, `adjustment`) with `balance_after`.
  Every balance change goes through `StockService::move()`, which locks the row, rejects negative stock and writes the ledger row.
- **Atomicity.** Sales, transfers, purchases, adjustments, payments and bill cancellation each run in one DB transaction
  with row locks (`SELECT … FOR UPDATE`). Any failure rolls back everything.
- **Inventory valuation: weighted average cost.** `products.avg_cost` is recalculated over the combined Store+Shop quantity
  whenever stock arrives at a cost (purchase, opening stock, cancelled sale):
  `new_avg = (on_hand × avg + qty × unit_cost) / (on_hand + qty)`.
  Each `sale_items` row stores `unit_cost` at the moment of sale, so historical profit never changes when later purchases cost more.
  Stock value = quantity × average cost.
- **Profit.** Gross profit = revenue − COGS (stored per sale item). Net profit = gross profit − expenses for the period.
- **Udhari.** Udhari sales require a customer and post a debit; payments post a credit and may not exceed the outstanding balance.
  `customers.balance` is a cached running balance updated in the same transaction; the ledger recomputes running balances in date order.
- **Money** is `NUMERIC` in PostgreSQL and calculated with bcmath strings in PHP (`App\Support\Money`) — never floats.
- **Errors** are always friendly and localized (`X-Locale: mr|en`); technical details only go to the Laravel log.

## Local development

Requirements: PHP 8.2+ (`pdo_pgsql`, `bcmath`, `mbstring`), Composer, Node 20+, PostgreSQL 14+.

```bash
# Backend
cd backend
composer install
cp .env.example .env          # set DB_*, FRONTEND_URL, ADMIN_* (APP_ENV=local, APP_DEBUG=true for dev)
php artisan key:generate
php artisan migrate --seed    # owner account, categories, expense categories, settings
php artisan db:seed --class=DemoSeeder   # optional sample data (NOT for production)
php artisan serve --port=8765

# Frontend
cd frontend
npm install
echo "VITE_API_URL=http://127.0.0.1:8765/api" > .env.local
npm run dev
```

Default login (change it in Settings → Account): mobile `9999999999` / email `owner@sanjgarva.in`, password `ChangeMe@123`
(from `ADMIN_*` in `.env`).

Tests: `cd backend && php artisan test` (stock flow, transfer/sale validation and rollback, udhari & ledger,
weighted-average COGS, P&L, bill cancellation, adjustments, auth, every read/export endpoint).

## Replacing the logo

- In the app: Settings → Upload Logo (stored in the database, works on any host).
- Default logo everywhere (login, header, sidebar, splash, favicon, PWA/home-screen icons): `frontend/branding/logo-source.png`.
  To change it, replace that file and run `npm run icons` in `frontend/` — it regenerates `src/assets/logo.webp`,
  `public/favicon.png`, `public/logo-256.png` and `public/icons/*.png` in optimized sizes.

## Deployment

### Frontend → Vercel
1. Import the repo, set **Root Directory** = `frontend` (framework: Vite).
2. Environment variable: `VITE_API_URL=https://api.yourdomain.com/api`
3. `vercel.json` already rewrites all routes to `index.html` (React Router) and sets cache headers for the service worker and assets.

### Backend → PHP host (VPS, Forge, Ploi, Laravel Cloud, Render…)
1. Document root → `backend/public`. PHP 8.2+ with `pdo_pgsql` and `bcmath`.
2. `.env` from `.env.example`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `DB_*` (PostgreSQL), `FRONTEND_URL=https://your-app.vercel.app`
   (comma-separate several origins; `FRONTEND_URL_PATTERN` for Vercel preview URLs).
3. Deploy commands:
   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan key:generate   # first deploy only
   php artisan migrate --force
   php artisan db:seed --force   # first deploy only (creates the owner account)
   php artisan config:cache && php artisan route:cache
   ```
4. HTTPS is required for the PWA install prompt and for secure token transport.

Auth uses Sanctum **Bearer tokens** (16 h, or 30 days with "Keep me logged in"); there are no cookies, so CSRF does not apply to the API.
Login is rate-limited (5/min per login+IP), the API to 300/min per user. Never commit `.env`.

## API overview (`/api`, JSON `{ success, data, message?, meta? }`)

```
POST auth/login · GET auth/me · POST auth/logout · PUT auth/password
GET|PUT settings · POST|DELETE settings/logo · GET dashboard
categories (CRUD) · products (CRUD, ?search&category_id&status)
GET store-stock|shop-stock (?search&status=good|low|out) · POST store-stock/add · GET purchases
POST stock/transfer {items:[{product_id,quantity}]} · POST stock/adjust · GET stock/adjustments · GET stock/movements
GET|POST sales · GET sales/{id} · POST sales/{id}/void
customers (CRUD) · GET customers/{id}/ledger · POST customers/{id}/payment
expense-categories (CRUD) · expenses (CRUD)
GET reports/sales|stock|profit-loss|udhari|expenses (?period=today|yesterday|week|month|last_month|all or ?from&to)
GET export/{sales|sale-items|customers|udhari|expenses|stock|movements|purchases} → CSV (UTF-8 BOM, opens in Excel)
```
Error codes: `VALIDATION` (with `errors`), `INSUFFICIENT_STOCK`, `INSUFFICIENT_STORE_STOCK`, `PAYMENT_EXCEEDS_BALANCE`,
`HAS_HISTORY`, `ALREADY_VOIDED`, `NO_CHANGE`, `UNAUTHENTICATED`, `NOT_FOUND`, `TOO_MANY`, `SERVER_ERROR`.
