# Neon Database Setup — GoTrainee

No credentials appear in this document. The connection string exists only in the Render Backend's protected environment variables and in the Neon Console.

## 1. What exists (verified 2026-10-17)

| Item | Value |
|---|---|
| Neon organization | `Abood` (`org-lucky-poetry-49083484`), plan **Free**, no payment source |
| Neon project | `gotrainee` (`shy-cherry-96993664`) |
| Region | `aws-eu-central-1` (Frankfurt), the same metro as the Render Backend (`frankfurt`) |
| Engine | PostgreSQL 18.6 |
| Branch | `main` (`br-solitary-breeze-b9jzx8fj`), the default branch |
| Compute | primary read-write endpoint `ep-fancy-cake-b9jzxuoh`, 0.25 CU (Free default), scale-to-zero on inactivity |
| Database / owner role | `gotrainee` / `gotrainee_owner` |
| Connection type | direct (non-pooled) host, TLS required (`sslmode=require`) |
| Render Backend | `Backend` (`srv-db0h3vfavr4c73fmdo20`), free plan: https://backend-sq66.onrender.com |
| Frontend (unchanged) | https://gotrainee-web.onrender.com, built with `VITE_API_BASE_URL=https://backend-sq66.onrender.com/api` |
| Commit live on the Backend | `43575db19fe6fe58a08b2286546e59ba24c71673` (redeployed unchanged; only the database connection changed) |

The project was created with the official Neon CLI (`neonctl` 8.8.0), signed in through Neon's OAuth flow in the owner's browser. No Neon management API key was created, and nothing for Neon management is configured in the app.

## 2. Render configuration (names only)

Laravel's `pgsql` connection reads `DB_URL` (`config/database.php`), and `DB_SSLMODE` provides the fallback TLS mode.

| Variable | Kind | Content |
|---|---|---|
| `DB_CONNECTION` | plain | `pgsql` |
| `DB_URL` | **secret** | the full Neon connection URI for the direct endpoint, including `sslmode=require` |
| `DB_SSLMODE` | plain | `require` |
| `RUN_MIGRATIONS` | plain | `true`: `docker/entrypoint.sh` runs `php artisan migrate --force` on boot |
| `RUN_SEEDERS` | plain | `true`: idempotent reference data (skills, specializations); demo data is skipped in production |

Nothing else changed: `APP_KEY`, Gemini, Google OAuth, CORS and the frontend settings are as before.

Use the **direct** host, not the `-pooler` host. Migrations run over this connection, and a single free instance needs only a few connections. The Render image's libpq supports SNI, which Neon needs to route the connection. The old XAMPP libpq on the development PC does not, so local tools there must append `options=endpoint=<endpoint-id>`.

## 3. How the migration was applied

1. Before anything ran, a direct connection showed database `gotrainee`, role `gotrainee_owner`, a `*.neon.tech` host and **0 tables**. That proved the target was the new Neon database, not Railway and not the Render Postgres.
2. `DB_URL`, `DB_SSLMODE` and `DB_CONNECTION` were set on the Render service through the Render API. The values were never echoed.
3. The Backend was redeployed. On boot the entrypoint runs `config:cache` (so the new env is read), then `migrate --force`, the reference seeders, and finally Apache. A failed migration would exit the container, and Render would keep the previous instance serving.

**Observed problem:** the first redeploy, triggered right after the env update, booted with the *previous* environment. Its log said `Nothing to migrate`, and the existing staging admin was still found. A second redeploy picked up the Neon URL. **Lesson:** after changing env vars on Render, check the boot log (or the data) before assuming the new value is live.

## 4. Verification results

| Check | Result |
|---|---|
| Boot log (instance `tzdkk`) | all migrations `DONE` through `2026_10_17_000001_create_project_execution_tables`; `SkillSeeder` and `SpecializationSeeder` done; Apache started |
| Migrations table vs. `database/migrations` | 39 files, 39 rows, batch 1, none missing or extra |
| Required tables | all present (users, sessions, cache, skills, project workflow, plan, execution, evidence, audit, notifications); 46 tables total |
| Reference data | 65 skills, 7 specializations, 0 users (fresh database; the old test data was intentionally not migrated) |
| Read/write | insert, read and rollback inside a transaction succeeded; nothing left behind |
| `GET /api/health` | `200 {"status":"ok","database":"ok","driver":"pgsql","release":"43575db…"}` |
| `GET /api/skills` | `200`, `success: true`, 65 skills, created on the Neon boot (2026-10-17) |
| `POST /api/login` (unknown user) | `422`: the users lookup works without changing any data |
| `GET /api/me` without a token | `401` |
| CORS preflight from the frontend origin | `access-control-allow-origin: https://gotrainee-web.onrender.com` |
| Frontend bundle | still targets `https://backend-sq66.onrender.com/api` |
| Runtime log after the Neon boot | no SQLSTATE, TLS, authentication or connection-limit errors |
| Backend suite (in-memory SQLite, never Neon) | 214 passed, 3 skipped |
| Frontend | 131 unit tests passed; production build OK |

The application code is also tested on PostgreSQL 16 and MariaDB 10.4 (see `AUTONOMOUS_PROGRESS.md`). PostgreSQL 18 is covered by the real migration run above.

## 5. Free-tier behaviour and limits

- **Neon Free compute scales to zero** after a short idle period and wakes on the next connection, usually within a second or two. A sleeping compute is normal; it is not a broken or expired database. Don't add keep-alive traffic.
- Free plan allowances (compute hours per month, about 0.5 GB of storage per project, short point-in-time history) are set by Neon and can change; check the Neon Console's usage page. When the monthly compute allowance runs out, the compute is suspended until the next cycle, and no charge is made without a payment method.
- **Render Free web service** sleeps after about 15 minutes without traffic. The first request after that takes about 30–60 s (container start, then the Neon wake-up). Distinguish this from a real failure by retrying once.
- Every redeploy reruns `migrate --force` (a no-op when there is nothing new) and the idempotent seeders.

## 6. Old databases

- **Railway MySQL:** untouched and not used. Its data is disposable by owner decision.
- **Render Postgres `gotrainee-staging-db`** (free, expires 2026-11-15): no longer used by the Backend. It was **not deleted**. It costs nothing and expires on its own; the owner can delete it from the Render dashboard at any time.

## 7. Rollback

To return to the Render Postgres before it expires, set `DB_URL` back to its internal connection string (Render dashboard → database → Connections) and redeploy. Then check the boot log, as noted in §3.

## 8. Remaining manual steps (owner)

1. Register a real admin account in the app, set `ADMIN_PROMOTE_EMAILS` to that email, and redeploy. The variable still names a staging account that does not exist in the new database, so the promotion step is skipped at boot. This is harmless.
2. Optional: delete `gotrainee-staging-db` on Render once you no longer want a fallback.
3. Optional: revoke the `neonctl` OAuth session (Neon Console → Account → Authorized applications) if you don't want this PC to keep Neon management access.
