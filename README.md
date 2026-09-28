# FamBank

A small, mobile-first family savings ledger. FamBank helps a private group record deposits and withdrawals in Argentine pesos (ARS), track each member's balance in US dollars (USD), and review requests before they affect the ledger.

This repository contains the **first working version** of a personal project. The interface and workflows are being refined. FamBank is a record-keeping application; it does not hold money, move funds, provide banking services, or execute currency exchange.

## How it works

1. An administrator creates a member account.
2. A member signs in, sees their USD balance and transaction history, and requests a deposit or withdrawal in ARS.
3. The API retrieves the relevant exchange rate and calculates the USD equivalent. Members cannot submit their own rate.
4. The request stays pending for administrator review. A pending withdrawal reserves the corresponding USD balance; a pending deposit is credited only after confirmation.
5. An administrator can confirm or reject the request and adjust the exchange rate during confirmation. Rejected or canceled withdrawals restore the reserved balance.

Administrators can also create and immediately confirm a transaction on a member's behalf. Balance updates and transaction state changes are performed in database transactions, with row locking around the critical balance checks.

## First-version features

- Separate member and administrator dashboards.
- Sign-in, profile updates, and password reset through a Laravel Sanctum API.
- Member balances, paginated transaction history, and pending-request cancellation.
- Administrator member management and transaction review.
- ARS-to-USD conversion using an exchange-rate service, with the applied rate stored on each transaction.
- Push subscription endpoints and a progressive web app (PWA) frontend. The PWA is configured as an Android Share Target for content shared from other apps; receipt-processing behavior should be treated as future work unless implemented separately.
- API documentation generated with Scribe and health endpoints for the application and database.

The UI is currently in Spanish because the app was built for a family in Argentina. Project documentation is in English for developers.

## Stack

| Layer | Technology |
| --- | --- |
| Backend | PHP 8.2+, Laravel 12, Laravel Sanctum, PostgreSQL |
| Frontend | React 19, TypeScript, Vite, React Router, Tailwind CSS, Zustand, Axios |
| PWA | vite-plugin-pwa, web push support, Android Share Target configuration |
| API documentation | Scribe |
| Deployment | Railway configuration in the two applications |

## Repository structure

- [`fambank-api/`](fambank-api/README.md) — Laravel API, transaction rules, authentication, and database.
- [`fambank-app/`](fambank-app/README.md) — React/TypeScript PWA with member and administrator views.

## Run locally

You need PHP 8.2+, Composer, Node.js/npm, and PostgreSQL. Configure your own local database; do not use production credentials or real family data for development.

```bash
cd fambank-api
composer install
cp .env.example .env
# Set APP_ENV=local and your PostgreSQL DB_* values in .env.
php artisan key:generate
php artisan migrate
php artisan serve
```

In another terminal:

```bash
cd fambank-app
npm install
cp .env.example .env
# Set VITE_API_URL to the local Laravel URL if needed.
npm run dev
```

The API defaults to `http://localhost:8000`, and the Vite app normally runs at `http://localhost:5173`. See the [API](fambank-api/README.md) and [app](fambank-app/README.md) READMEs for service-specific notes. Administrator accounts and sample data are not provided here; configure them locally before trying the protected dashboards.

## Where it can go next

This is an initial release, not a finished product. The next iteration can improve usability, transaction evidence, reporting, automated tests, and the deployment workflow. These are directions for future development, not claims about the current version.

## Important scope

FamBank stores a private ledger of family transactions. Its displayed balances depend on transactions being entered and reviewed correctly; they are not bank balances. Keep personal data, credentials, and deployment secrets out of the repository.
