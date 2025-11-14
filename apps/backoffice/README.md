# Atlas POS Backoffice

The Atlas Backoffice is the React (Vite + Tailwind + shadcn/ui) dashboard used by managers to manage products, images, and per-store inventory levels.

## Prerequisites

- Node.js 20.x (or newer)
- npm 10.x (ships with Node 20)
- The API running locally at `http://127.0.0.1:8000` (update the URL if your API host differs)

## First-Time Setup

1. Install workspace dependencies from the repository root:
   ```bash
   npm install
   ```
2. Create `apps/backoffice/.env` and point it at the API:
   ```bash
   VITE_API_URL=http://127.0.0.1:8000/api
   ```
3. Start the Backoffice dev server:
   ```bash
   npm --workspace apps/backoffice run dev
   ```
   The app defaults to `http://127.0.0.1:5174`.

## Inventory Management Highlights

- Products now expose a **Stock** tab that lists per-store quantities and allows inline adjustments with reasons (manual adjustment, initial stock, correction, wastage).
- Stock adjustments call the `/bo/stocks/adjust` API and optimistically refresh the list by invalidating the `['bo', 'products']` query.
- Image uploads hit `/bo/uploads/images`, storing files on Laravel's `public` disk while keeping code paths compatible with S3.
- Access to stock edits is limited to admin/manager roles; cashiers see the read-only product details.

## Build & Lint

- Production build (includes TypeScript project check): `npm --workspace apps/backoffice run build`
- Lint with ESLint: `npm --workspace apps/backoffice run lint`

Run these commands before opening a pull request to ensure the front-end compiles and passes linting.
