# FamBank API

Backend Laravel 12 para FamBank.

## Stack

- PHP 8.2+
- Laravel 12
- Laravel Sanctum (autenticación)
- Scribe 5.x (documentación de API)
- PostgreSQL

## Builder

Usa **Nixpacks** en Railway. El `composer install` corre durante el build sin necesidad de `composer.lock` local.

## Variables de entorno requeridas en Railway

| Variable | Descripción |
|----------|-------------|
| `APP_KEY` | Generada y cargada en Railway |
| `APP_URL` | URL pública del servicio |
| `DB_HOST` | Host de PostgreSQL (referencia interna Railway) |
| `DB_PORT` | 5432 |
| `DB_DATABASE` | fambank |
| `DB_USERNAME` | fambank |
| `DB_PASSWORD` | — |
| `SANCTUM_STATEFUL_DOMAINS` | Dominio del frontend |

## Endpoints

- `GET /up` — health check de Laravel (Railpack)
- `GET /api/health` — health check con estado de DB

## Documentación

Una vez deployado: `https://tu-api.railway.app/docs`
