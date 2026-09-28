# FamBank API

Laravel 12 API for the FamBank family savings ledger. It handles member authentication, ARS deposit and withdrawal requests, USD balances, administrator review, exchange-rate lookup, and push subscriptions.

## Stack

- PHP 8.2+, Laravel 12, Laravel Sanctum
- PostgreSQL for deployed environments; SQLite in-memory for automated tests
- Scribe for API documentation
- BCMath-backed monetary calculations

## Local development

```bash
composer install
cp .env.example .env
# Set APP_ENV=local and configure your local PostgreSQL DB_* values.
php artisan key:generate
php artisan migrate
php artisan serve
```

Run `php artisan test` for the backend suite. The repository intentionally does **not** seed a default administrator account or password. Provision the first administrator privately with a unique password; do not commit account details or real family data.

## Main API flows

- `POST /api/auth/login`, `POST /api/auth/forgot-password`, `POST /api/auth/reset-password`
- `GET|POST /api/transactions` and `DELETE /api/transactions/{transaction}`
- `GET /api/admin/users` and administrator member-management routes
- `GET /api/admin/transactions`, plus confirm/reject routes
- `GET /api/exchange-rate`
- `GET /api/health` for API and database health; `GET /up` for Laravel health

Member transactions use a server-side exchange rate. Pending withdrawals reserve the USD amount, while pending deposits are credited after approval. See `routes/api.php` for the complete route list and generated Scribe documentation at `/docs` when configured.

## Deployment

The app has been configured for Railway. Set `APP_KEY`, `APP_URL`, PostgreSQL connection variables, `FRONTEND_URL`, and `SANCTUM_STATEFUL_DOMAINS` in the deployment environment. Configure mail and VAPID keys if using password-reset email and push notifications. Never put production secrets in `.env.example` or the repository.
