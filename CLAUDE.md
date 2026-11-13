# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

TCC (capstone) project: registration and management system for a Catholic community's camps (**Acampamentos**) and events (**Eventos**) — "Acampamento São Miguel Arcanjo". Monorepo with two apps:

- `backend/` — CakePHP 5.3 JSON API (PHP ≥ 8.2, MySQL 8). The README there is just the stock Cake skeleton README.
- `front-acamp/` — SvelteKit 2 + Svelte 5 (runes forced on for all project files in `svelte.config.js`) + TypeScript. README is the stock `sv` template.
- `Desing/` (sic) — Claude Design handoffs (prototypes + README with color/type tokens). Gitignored; reference only, not part of the app.

Code, identifiers, UI text, comments and commit messages are in **Portuguese**. Keep new code that way.

Runs on Windows 11 (the app is only ever run locally for the TCC presentation — no deploy, no cron/scheduler).

## Commands

Backend (run from `backend/`):

```sh
php -S localhost:8765 -t webroot          # dev server (or: php bin/cake.php server -p 8765)
php vendor/bin/phpunit                     # full test suite
php vendor/bin/phpunit tests/TestCase/Regras/SorteioServosTest.php          # one file
php vendor/bin/phpunit --filter nomeDoTeste tests/TestCase/Regras/X.php     # one test
composer cs-check                          # phpcs (CakePHP standard); composer cs-fix to auto-fix
php bin/cake.php migrations migrate        # on Windows use bin/cake.php — bin/cake is a shell script
php bin/cake.php migrations seed
php bin/cake.php executar_sorteios_pendentes   # CAUTION: performs real raffles on the dev DB
php bin/cake.php executar_prazos_formulario    # CAUTION: really disqualifies people past deadlines
```

Frontend (run from `front-acamp/`):

```sh
npm run dev -- --port 5173   # .env sets VITE_API_URL=http://localhost:8765/api/v1
npm run check                # svelte-check (type checking) — the only static check; there is no linter or JS test suite
npm run build
```

Config/secrets live in `backend/config/.env` (DB, JWT secret, Mercado Pago) and `front-acamp/.env`; neither is in git. **Never run commands that put the DB password on the command line** (e.g. `mysql -pSECRET`). To inspect the DB, write a throwaway PHP script in the scratchpad that requires `vendor/autoload.php` + `config/bootstrap.php` and uses `ConnectionManager::get('default')`; wrap experiments in `begin()`/`rollback()`.

### Tests

- Tests run against a real MySQL database: connection `test` in `config/app_local.php` reuses the `DATABASE_*` vars with `DATABASE_TEST_NAME` (default `<DATABASE_NAME>_test`). `tests/bootstrap.php` aborts if the test DB equals the app DB, runs all migrations via `Migrator`, then clears the `_cake_model_` schema cache.
- The real tests are in `tests/TestCase/Regras/` and extend `RegrasTestCase` (transaction per test; factories `criarUsuario(admin: true)` = all permissions, `criarAcampamento`, `inscrever`, `jaFoiCampista`, `criarPergunta`, `autenticarComo`, `respostaJson`). Each business rule has a test file there — add/extend one when changing a rule.
- In `IntegrationTestTrait`, request config is reset after each request: call `autenticarComo()` again before every `get`/`post`.
- Mercado Pago is mocked with `Client::addMockResponse` (see `PagamentoTest`).

## Backend architecture

- **Routing**: every API endpoint is declared explicitly in `config/routes.php` under prefix `Api/V1` → `src/Controller/Api/V1/*`. There are no fallback routes for the API — new endpoints need a route line. `src/Controller/Api/AuthController.php` (no V1) is **dead code**; the real login is `Api/V1/AuthController`. Check the route before editing a controller.
- **Auth**: `JwtAuthMiddleware` decodes `Authorization: Bearer <token>` (access tokens only, non-deleted users) and sets the `identity` request attribute. CSRF is skipped for `/api/*`; CORS is a permissive inline middleware in `Application.php`.
- **`ApiController`** (base of all V1 controllers): `apiResponse()`/`apiError()` write raw JSON; `requireIdentity()` and `requirePermissao('<chave>')` return either the user or an error `Response`, so the idiom is:
  ```php
  $identity = $this->requirePermissao('setores');
  if ($identity instanceof Response) return $identity;
  ```
  `serializeUser()` is the single source of the logged-in user payload (login, register, `/users/me`, `/auth/me`) — including `permissoes`.
- **Permissions**: the `user_permissions` table is the *only* access rule (`users.is_admin`/`is_counselor` were dropped). Screen keys and dependencies live in `PermissoesService::TELAS`/`AREAS`; anyone with ≥1 screen enters the admin panel. Team roles inside an activity (servo geral / líder de setor / servo comum) are a separate, per-activity mechanism in `ActivitiesController` (`activities_general_servants`, `is_coordinator`).
- **Services** (`src/Service/`) hold the heavy business logic; controllers are thin wrappers: `SorteioService` (campista/servo raffles), `PrazoFormularioService` (form/payment deadlines, open slots, "chamar próximo"), `MercadoPagoService` (Checkout Pro; unconfigured on purpose — `configurado()` is false without a token), `PermissoesService`, `JwtService`, `TravaExecucao` (MySQL `GET_LOCK` wrapper).
- **Lazy-cron**: pending raffles and deadline sweeps run opportunistically at the top of `ActivitiesController::index`, `SorteioController::resultado` and several `InscricoesController` actions (guarded by `TravaExecucao`, each raffle date handled once). Consequence: simply hitting `GET /activities` or `/me/inscricoes` can mutate the dev DB — intercept those in browser tests when a raffle date is near.
- **Soft delete**: most tables have a `deleted` column; queries must filter `deleted IS NULL` (including on joined `Users`) — this has caused leaks before.

### Backend pitfalls that have bitten this project

- **Never hardcode lookup-table ids** (`selection_methods`, `marital_statuses`, `categories`): seed order has changed more than once. Look them up by `title`/name (see `getRaffleMethodId()`, `getCasadoMaritalStatusId()`).
- Once a query has `contain()`, qualify columns in `where()` with the table name (`'PreRegistrations.id' => ...`) or MySQL throws ambiguous-`id` errors.
- Response envelopes are inconsistent across endpoints (`data` vs `activity` vs `categories` …). When a screen "loads empty" with no error, check the real response shape before suspecting the DB.
- After a migration, if a new column silently "doesn't save" in dev, clear `tmp/cache/models` (Cake caches table schema for a long time).
- Migrations must run on MySQL (old ones use `enum`; SQLite is not supported). A fresh DB = `migrations migrate` + `migrations seed`; seeds cover lookup tables, 8 sectors, the admin user and the real FAC/Juvenil forms (`FormulariosSeed`), not test data.

## Frontend architecture

- `src/lib/api.ts` — `apiFetch(endpoint, opts)`: prefixes `VITE_API_URL`, adds the Bearer token from `localStorage`, and on 401 clears the token and redirects to `/login?redirect=…` (pass `redirectOnUnauthorized: false` for public screens).
- `src/lib/stores/auth.svelte.ts` — runes-based `auth` store (`auth.user`, incl. `permissoes`). Only a 401 should drop the saved token.
- `src/lib/services/*Service.ts` — thin per-domain wrappers over `apiFetch`; types in `src/lib/types/models.ts`.
- Routes: `/dashboard/**` is the participant area and is **public** (visitors can browse activities; subscribing goes to login via `src/lib/redirect.ts`); `/admin/**` is the panel ("Painel da Comunidade").
- **Panel permissions are mirrored** in `src/lib/permissoes.ts` (`MENU_DO_PAINEL`, `TELA_POR_CAMINHO`) + `routes/admin/+layout.svelte`. Requirement: a limited user must not notice restrictions — the menu shows only allowed screens and a blocked URL silently redirects to the first allowed one; 403 messages are neutral. Adding a panel screen means updating `PermissoesService::TELAS`, `permissoes.ts`, and the backend `requirePermissao` calls together.
- Business rules shown in the UI (e.g. servo/campista eligibility in `dashboard/atividades/[id]`) are partial mirrors; the backend is always the real gate.
- Shared create/edit camp form: `src/lib/components/AcampamentoForm.svelte` (its `DESTINO` table maps form fields → `activity`/`camping` columns; edit loads from `GET /activities/{id}/edit` and saves with `_method=PATCH`).
- Visual identity: ivory background, stained-glass blue `#1B2F5E`, gold `#B69E55`, red `#9B2C2C`, Cormorant Garamond + Source Sans 3. Login/register use `AcessoLayout.svelte` + `lib/styles/acesso.css` (`ac-` prefix); older admin screens use `lib/styles/admin-form.css`. Desktop only for now.

### Svelte 5 / TS gotchas

- `$derived(x && x.prop)` or a ternary on a nullable makes svelte-check infer `never`; use `$derived.by(() => { if (!x) return fallback; return x.prop; })`.
- `$page.params.id` is typed `string | undefined`; cast at read time (`$page.params.id as string`).

## Domain glossary (quick)

- **Campista** = camper; **Servo** = volunteer/servant. Categories chain Mirim → FAC → Juvenil → Sênior, plus independent Casais and Discipulados. One can be Campista in a category only once; Servo requires prior Campista in that category or a lower one. These rules apply only to Acampamentos, not Eventos.
- **Sorteio** = raffle (titulares + suplentes). **Indicação** (Base/Forania/Conselho) = manual pick that survives re-raffles and exceeds the quota. Couples are drawn/disqualified as a unit. Only residents of Primavera do Leste/Poxoréu are drawn.
- **Prazo** = deadline. Missing the form or payment deadline frees the slot; an admin clicks "Chamar próximo" to promote the next suplente (no automatic promotion).
- `pre_registrations` = a subscription; `activities` is the base record, with `campings` or `events` holding type-specific fields; sectors: `sectors` → `categories_sectors` (template vacancies) → `activities_categories_sectors` (per edition) → `activities_categories_sectors_users` (team members, `is_coordinator`, `is_substitute`, `is_indicated`).

## Working conventions

- Commit/push only when the user asks; commit messages in Portuguese explaining the *why*.
- Browser testing: log in via `/login` (seed admin CPF `00000000000`; test users use `senha123`), name test data "TESTE CLAUDE …", and delete it (and any files uploaded to `backend/webroot/uploads/`) afterwards.
