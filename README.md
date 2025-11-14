# Atlas POS Monorepo

Atlas POS is a full-stack point of sale solution that ships with:

- **Laravel API** (`/api`) for multi-tenant backoffice and POS endpoints.
- **Backoffice web app** (`/apps/backoffice`, React + Vite) for tenant administration.
- **POS web app** (`/apps/web-pos`, React + Vite) for frontline sales teams.
- Shared UI components, desktop shell, and infrastructure tooling.

This repo is already wired so a freshly seeded default tenant can log in end-to-end via the login preset selector.

---

## Prerequisites

- PHP 8.2+, Composer
- Node.js 18+, npm
- PostgreSQL (default local port `5433` as per `.env.testing`)
- Optional: Redis, Memcached (only if you enable the relevant drivers)

---

## Backend Setup (`/api`)

```bash
cd api
cp .env.example .env        # adjust DB credentials if needed
composer install
php artisan key:generate
php artisan migrate --seed  # runs TenantDemoSeeder (default tenant/store/user)
php artisan serve           # serves http://127.0.0.1:8000
```

Seeding ensures these demo credentials always exist (idempotent even if rerun):

- Tenant slug: `default`
- Email: `manager@example.com`
- Password: `password`

Public login presets are available at `GET /api/public/tenants/presets`.

---

## Frontend Apps

### Backoffice (`/apps/backoffice`)

```bash
cd apps/backoffice
cp .env.example .env        # ensure VITE_API_URL points to the Laravel API
npm install
npm run dev                 # http://localhost:5173
```

On load the login page fetches tenant presets and auto-selects the seeded `default` tenant, so you can log in immediately with the demo credentials above. The app stores the selected tenant slug and echoes it beneath the selector to avoid confusion between Docker/local databases.

### POS (`/apps/web-pos`)

```bash
cd apps/web-pos
cp .env.example .env
npm install
npm run dev                 # http://localhost:5174
```

---

## Testing

Run backend tests (including the multi-tenant login feature test):

```bash
cd api
php artisan test
```

Run backoffice unit/UI tests:

```bash
cd apps/backoffice
npm run lint
npm run test
```

Vitest runs in watch mode by default; pass `--runInBand` for CI environments.

---

## Default Workflow

1. Start PostgreSQL and Redis (if used).
2. `php artisan serve` from `/api`.
3. `npm run dev` from `/apps/backoffice` (and `/apps/web-pos` if needed).
4. Log in via `http://localhost:5173/login` using the preset tenant and demo credentials.

Feel free to open issues or PRs in [Azimiwizard/Atlas.pos](https://github.com/Azimiwizard/Atlas.pos) if you expand functionality or encounter bugs.

