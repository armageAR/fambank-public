# FamBank — Review técnico y plan de mejora

**Fecha:** 2026-06-10 · **Branch:** `development` · **Alcance:** backend (`fambank-api`, Laravel 12) + frontend (`fambank-app`, React 19 PWA)

---

## 1. Resumen ejecutivo

El proyecto está bien encaminado para un MVP: arquitectura limpia (controllers finos, FormRequests, Resources, Enums, Services), buena UX mobile-first, auth sólida con Sanctum + rate limiting de login, y 48 tests que pasan. Sin embargo, **la lógica de dinero —el corazón del sistema— tiene condiciones de carrera, confía en datos del cliente y no tiene ni un solo test**. Además el CI solo corre en `main`, por lo que nada de lo que se desarrolla en `development` se valida automáticamente.

Prioridad sugerida: **integridad del dinero primero**, seguridad/operación después, calidad y features al final.

---

## 2. Hallazgos críticos (integridad de dinero)

### C1. Condiciones de carrera en el saldo — sin locks ni checks atómicos
Afecta a los 4 puntos que mueven plata:

- `TransactionController::store` (`fambank-api/app/Http/Controllers/Api/TransactionController.php:63-70`): el chequeo de saldo suficiente se hace **fuera** de `DB::transaction()` y sin `lockForUpdate()`. Dos retiros concurrentes que individualmente caben en el saldo pasan ambos el check y dejan el saldo negativo.
- `TransactionController::cancel` (líneas 151-162): el check `status !== Pending` está fuera de la transacción. Dos cancels concurrentes (doble tap del usuario) → **doble refund**.
- `Admin\TransactionController::confirm` y `reject` (líneas 43-45 y 96-98): mismo patrón. Confirm + reject concurrentes sobre la misma tx → acreditación y refund duplicados.

**Fix:** mover el check de estado/saldo adentro de `DB::transaction()` releyendo el registro con `lockForUpdate()`:

```php
DB::transaction(function () use ($transaction) {
    $tx = Transaction::lockForUpdate()->findOrFail($transaction->id);
    if ($tx->status !== TransactionStatus::Pending) {
        abort(422, 'Solo se pueden cancelar transacciones pendientes.');
    }
    // ... resto de la lógica
});
```
Y para el saldo: `User::lockForUpdate()->find($member->id)` antes de validar/decrementar.

### C2. El `exchange_rate` lo manda el cliente sin validación server-side
`CreateTransactionRequest` solo valida `numeric|min:1`. Un member malicioso (o un bug del frontend) puede crear un **retiro con TC=1.000.000**: se le descuentan centavos de USD por una solicitud de retiro grande en ARS. El admin puede ajustar el TC al confirmar, pero el default que ve es el TC enviado por el cliente, y el descuento de saldo ya ocurrió al crear.

**Fix:** obtener la cotización en el servidor (con el caché de S2) y validar que el rate enviado esté dentro de una tolerancia (ej. ±5% del blue actual), o directamente ignorar el rate del cliente para members y fijarlo server-side.

### C3. Aritmética monetaria con floats
Casts `(float)` y `round(..., 2)` en todos los cálculos (`store`, `confirm` con `abs($adjustment) >= 0.001`). Las columnas son `decimal` pero PHP opera en float → deriva de centavos acumulativa.

**Fix (mediano plazo):** operar en centavos enteros o con `bcmath`/`brick/money`. Como mínimo, centralizar el cálculo `ars → usd` en un solo método del modelo/servicio para que el redondeo sea idéntico en alta y confirmación.

### C4. Saldo mutable sin reconciliación
`users.balance_usd` se muta con `increment/decrement` y no hay forma de verificar que sea consistente con el historial. Si un bug (ver C1) lo corrompe, nadie se entera.

**Fix:** comando artisan de reconciliación (`balance_usd == Σ depósitos confirmados − Σ retiros confirmados/pendientes`) corriendo en schedule + alerta si difiere. Es barato y da red de seguridad inmediata sin rediseñar a event-sourcing.

---

## 3. Seguridad

| # | Hallazgo | Detalle | Fix |
|---|----------|---------|-----|
| S1 | Endpoints públicos sin throttle ni caché | `/api/exchange-rate` proxyea Bluelytics en cada request sin caché ni rate limit: vector de DoS y abuso contra la API de terceros. `/api/vapid-public-key` y `/api/health` tampoco tienen throttle. | `Cache::remember('blue', 300, ...)` en `ExchangeRateService` + middleware `throttle:60,1` en rutas públicas. |
| S2 | `/api/health` filtra información | Devuelve el mensaje de error crudo de la DB (puede incluir host/credenciales parciales) y el `env` a cualquier visitante (`routes/api.php:60-87`). | Público: solo `ok/error`. Detalle solo autenticado-admin o con token de health-check. |
| S3 | Rate limiter de login keyed solo por email | Un atacante puede bloquear la cuenta de otro usuario a voluntad (5 intentos con su email). | Key compuesta `email|ip` (`AuthController.php:32`). |
| S4 | Tokens Sanctum sin expiración | No hay config de `expiration`; un token robado vale para siempre. | Publicar `config/sanctum.php` con `expiration` (ej. 43200 = 30 días) + `artisan sanctum:prune-expired` en schedule. |
| S5 | Token en `localStorage` | `zustand/persist` guarda el token en localStorage → expuesto a XSS. Aceptable para este MVP (sin dependencias de terceros en runtime), pero documentarlo; la alternativa es cookie httpOnly + Sanctum SPA mode. | Documentar decisión; mitigar con S4. |
| S6 | CORS default | No hay `config/cors.php` publicado → default permite cualquier origin en `api/*`. Con bearer tokens el riesgo es bajo, pero conviene restringir a `FRONTEND_URL`. | Publicar config y fijar `allowed_origins`. |

---

## 4. Bugs funcionales

- **B1 — No existe estado `cancelled`:** `cancel` del member marca la tx como `Rejected` (`TransactionController.php:161`). En la auditoría no se distingue "el admin la rechazó" de "el usuario se arrepintió" (solo por `confirmed_by` null, frágil). Agregar `Cancelled` al enum + migración.
- **B2 — Admin puede crear transacciones para usuarios inactivos o para otro admin:** `store` hace `User::findOrFail($request->input('user_id'))` sin validar `active` ni `role` (`TransactionController.php:52`).
- **B3 — Handlers sin manejo de errores en `DashboardAdmin.tsx`:** `handleConfirm`, `handleActivate`, `handleDeactivate` (líneas 512-526) hacen `await` sin try/catch → si la API falla hay unhandled rejection, ningún feedback al usuario, y en deactivate el estado local ya se puede desincronizar.
- **B4 — División protegida con `|| 1`:** en `PendingTxCard` (`DashboardAdmin.tsx:345`), si el admin borra el campo TC, `usd = ars / 1` muestra un monto absurdo. Mostrar "—" si el TC no es válido.
- **B5 — `notificationclick` hardcodea `/dashboard/admin`** (`sw.ts:36`). Hoy solo los admins reciben push, pero es una mina si se agregan notificaciones a members. Navegar a `/` y dejar que `RootRedirect` resuelva.
- **B6 — Push notifications sincrónicas en el request:** `notifyAdmins` hace los HTTP requests a los push services dentro del ciclo del request del member (`TransactionController.php:118-129`). Con varios admins/subscripciones, el "crear transacción" se vuelve lento. Mover a un Job en queue (hoy `QUEUE_CONNECTION=sync`; pasar a `database`).
- **B7 — `PushSubscription` keyed por `(user_id, auth)`:** la clave natural de una suscripción push es el `endpoint`. Re-suscribirse genera filas nuevas y deja huérfanas las viejas hasta que el push falle. Unique por endpoint (hash, porque es `text`) y `updateOrCreate` por endpoint.

---

## 5. Calidad y deuda técnica

- **T1 — Cero tests de transacciones.** Los 48 tests cubren auth, admin/users y exchange rate. `TransactionController` (member y admin) —donde vive todo el dinero— no tiene ni uno. Es el gap de testing más grave del proyecto.
- **T2 — CI nunca corre sobre `development`.** `.github/workflows/backend.yml` solo dispara en push/PR a `main`. Todo el trabajo diario queda sin validar. Además no hay workflow de frontend (ni `tsc`, ni lint, ni build).
- **T3 — Frontend sin ESLint, sin Prettier, sin tests.** No hay script `lint` ni `test` en `package.json`.
- **T4 — `DashboardAdmin.tsx` con 699 líneas.** Modales, cards y dashboard en un solo archivo. Extraer a `components/admin/`.
- **T5 — Feature de attachments a medias.** Existen `Attachment` (modelo completo con accessors), la migración, y el `share_target` en el manifest PWA, pero no hay ningún endpoint ni UI. Decidir: completarlo (es la feature de "comprobantes") o sacarlo del árbol hasta que se haga.
- **T6 — `TransactionType::Adjustment` y `affectsBalance()` sin uso.** Si los ajustes manuales son un caso real (lo son, en un banco familiar), implementarlos; si no, eliminar.
- **T7 — Faltan índices en `transactions`.** En Postgres las FK no crean índices automáticamente... Laravel sí indexa `foreignId()->constrained()`, pero falta índice compuesto para las queries reales: `(user_id, created_at)` para el historial y `(status)` para pendientes.
- **T8 — Columnas `enum` nativas.** `$table->enum()` en Postgres genera check constraints; agregar un valor (ej. `cancelled` de B1) requiere alterar el constraint. Considerar migrar a `string` + validación por Enum de PHP.
- **T9 — Duplicación en frontend.** `NewTxModal` (admin) y `NewTransactionModal` (member) son ~80% iguales; ídem la card de transacción del historial vs `TxRow`; `fmt()` está definida en dos archivos; la card de "Dólar blue" está copiada entera en ambos dashboards. Extraer `components/shared/`.
- **T10 — `balance_usd` nullable con default 0.** Obliga a `parseFloat(member.balance_usd ?? '0')` por todos lados. Hacerla `NOT NULL`.
- **T11 — Falta `config/sanctum.php` y `cors.php` publicados** (ver S4/S6) y el `.env.example` no documenta `MAIL_*` (necesario para reset de password en producción).

### Lo que está bien (mantener)

- Separación clara API/SPA, controllers finos, FormRequests con mensajes en español, Resources consistentes.
- Rate limiting de login con feedback de `available_in`, anti-enumeración en forgot-password, revocación de tokens en reset/cambio de password/desactivación.
- Soft-deactivate de usuarios en vez de borrado (preserva historial).
- El modelo "ARS es inmutable, USD se recalcula al confirmar" está bien razonado y documentado en comentarios.
- Tests de auth exhaustivos; CI con cache de composer.
- El cambio sin commitear (VAPID key servida por la API en vez de build-time env) es una mejora correcta — falta commitearlo.

---

## 6. Plan de mejora por fases

### Fase 1 — Integridad del dinero (hacer antes de sumar usuarios reales)
1. **Locks + checks atómicos** en `store`, `cancel`, `confirm`, `reject` (C1). ~½ día.
2. **Validación server-side del exchange_rate** con caché de cotización y tolerancia (C2 + parte de S1). ~½ día.
3. **Estado `Cancelled`** + migración de enum a string (B1 + T8). ~½ día.
4. **Suite de tests de transacciones**: alta member/admin, saldo insuficiente, confirm con ajuste de TC (deposit y withdrawal), reject con refund, cancel, doble-confirm, permisos. Es la inversión más rentable del plan. ~1-2 días.
5. **Comando de reconciliación de saldos** + schedule (C4). ~½ día.

### Fase 2 — Seguridad y operación
6. Caché (5 min) + `throttle` en endpoints públicos; health sin detalles (S1, S2). ~½ día.
7. Rate limiter de login por `email|ip` (S3). ~1 h.
8. Expiración de tokens Sanctum + prune (S4) y CORS restringido (S6). ~2 h.
9. **CI en `development`**: agregar el branch a los triggers del workflow backend + workflow nuevo de frontend (`tsc -b`, `vite build`, lint cuando exista). ~2 h.
10. Queue `database` + Job para push notifications (B6). ~½ día.
11. Validar member activo/rol en alta de tx por admin (B2). ~1 h.

### Fase 3 — Calidad de código
12. ESLint + Prettier en frontend, script `lint` en CI (T3). ~2 h.
13. Refactor frontend: extraer modales/cards compartidos, `fmt()` a `lib/format.ts`, partir `DashboardAdmin.tsx` (T4, T9). ~1 día.
14. Manejo de errores + feedback (toast simple) en los handlers del admin (B3, B4). ~½ día.
15. Índices en `transactions`, `balance_usd NOT NULL` (T7, T10). ~2 h.
16. Migrar aritmética a centavos enteros o `brick/money` (C3). ~1 día.
17. Tests de frontend con Vitest para la lógica pura (cálculo USD, stores, hooks). ~1 día.

### Fase 4 — Producto
18. **Comprobantes (attachments)**: endpoints de upload + UI + share target de la PWA, o eliminar el código muerto (T5).
19. **Ajustes manuales** del admin usando `TransactionType::Adjustment` (T6).
20. Push a members cuando el admin confirma/rechaza su operación (con B5 resuelto).
21. Listado completo/filtrable de operaciones para el admin (hoy solo pendientes + historial por usuario).

---

## 7. Quick wins inmediatos

1. Commitear el cambio pendiente de VAPID key (ya está probado y es correcto).
2. Agregar `development` a los triggers del CI — una línea en el workflow.
3. `Cache::remember` en `ExchangeRateService` — 3 líneas, elimina el problema más explotable.
4. Try/catch + mensaje de error en `handleConfirm`/`handleDeactivate` del admin.
