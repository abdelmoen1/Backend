# Render Deployment Runbook — GoTrainee

No credentials appear in this document. Secrets live only in Render's environment configuration.

## 1. Current topology (verified 2026-10-16)

| Piece | Render resource | URL / ID | Source |
|---|---|---|---|
| Backend API (Laravel 12, PHP 8.3, Apache) | Web service `Backend`, Docker, **free** plan, Frankfurt | https://backend-sq66.onrender.com — `srv-db0h3vfavr4c73fmdo20` | `abdelmoen1/Backend` (`Dockerfile`) |
| Frontend (React 19 + Vite) | Static site `gotrainee-web`, free | https://gotrainee-web.onrender.com — `srv-db94q4nbtjjs7395r060` | `abdelmoen1/Frontend` |
| Database (since 2026-10-17) | **Neon Free** PostgreSQL 18, project `gotrainee` (`shy-cherry-96993664`), `aws-eu-central-1` | direct endpoint `ep-fancy-cake-b9jzxuoh`, connection in secret `DB_URL` | see `NEON_DATABASE_SETUP.md` |
| Former database (unused) | Render Postgres 16 `gotrainee-staging-db`, free, expires 2026-11-15 | `dpg-db94fk3rjlhs73c8jdtg-a` | kept only as a fallback; not deleted |

All three are in Render project **Go Trainee**, environment `evm-db0gvrad0e5s73bjm96g`. `render.yaml` in the backend repo documents the same configuration (Blueprint format).

**Database:** since 2026-10-17 the Backend uses a Neon Free PostgreSQL database (owner decision: zero cost; the old Railway and Render test data is disposable). Neon Free scales its compute to zero when idle and has usage limits; see `NEON_DATABASE_SETUP.md`. The cost options in §6 remain relevant if the project outgrows the free tier.

## 2. How the backend container starts

`docker/entrypoint.sh`:

1. `php artisan config:cache`, `route:cache`, `view:cache`, `storage:link`.
2. If `RUN_MIGRATIONS=true`: `php artisan migrate --force`. A failing migration exits the container, so the deploy fails and Render keeps the previous version serving.
3. If `RUN_SEEDERS=true`: `php artisan db:seed --force` (skills and specializations; idempotent; demo data is skipped in production). **Required on any new database:** without the specialization catalogue no student is eligible for any role, so applications fail with `not_eligible`.
4. If `ADMIN_PROMOTE_EMAILS` is set: promotes those **existing** accounts to Platform Admin (`gotrainee:promote-admin`). It never creates accounts or prints passwords.
5. `apache2-foreground` serving `public/` on `$PORT`.

Free Render web services have no pre-deploy command and no shell, which is why migrations run in the entrypoint. With one instance there is no overlapping migration risk. On a paid plan, move step 2 to a **pre-deploy command** (`php artisan migrate --force`) and set `RUN_MIGRATIONS=false`.

Health check: `GET /api/health` returns `200 {"status":"ok","database":"ok","driver":...,"release":"<git sha>"}` only when the database answers and is migrated, otherwise `503` without connection details. `release` is the commit Render deployed (`RENDER_GIT_COMMIT`), which shows what is live.

## 3. Environment variables (backend)

Non-secret, set by value: `APP_ENV=production`, `APP_DEBUG=false`, `APP_NAME`, `APP_URL`, `APP_LOCALE=ar`, `FRONTEND_URL=https://gotrainee-web.onrender.com`, `LOG_CHANNEL=stderr`, `LOG_LEVEL=info`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=sync` (no queued jobs exist), `DB_CONNECTION`, `DB_SSLMODE`, `RUN_MIGRATIONS`, `RUN_SEEDERS`, `GEMINI_MODEL`, `GEMINI_TIMEOUT`, `GEMINI_MAX_RETRIES`, `GEMINI_RETRY_DELAY_MS`, `GOOGLE_REDIRECT_URI`.

Secret (never commit, never print): `APP_KEY` (pre-existing Render value, preserved), `DB_URL` (Render internal connection string), `GEMINI_API_KEY`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `ADMIN_PROMOTE_EMAILS`.

Optional platform settings (`config/gotrainee.php`): `GOTRAINEE_PLATFORM_COMMISSION_RATE` (default 0.10), `GOTRAINEE_REQUIREMENTS_GATHERING_FEE` (default 0), `GOTRAINEE_DEFAULT_CURRENCY` (USD), `GOTRAINEE_MAX_ACTIVE_LEADERSHIPS` (2), `GOTRAINEE_LEADER_MIN_VERIFIED_SKILLS` (1), `CORS_ALLOWED_ORIGINS` (comma-separated extra origins).

`APP_KEY` does not protect any stored data. No model uses encrypted casts, passwords are bcrypt, and Sanctum tokens are SHA-256 hashed. Rotating it only invalidates sessions and the short-lived Google OAuth state.

Frontend (build-time): `VITE_API_BASE_URL=https://backend-sq66.onrender.com/api`, `NODE_VERSION=22`. Never put server secrets in `VITE_*` variables. They are embedded in the public bundle.

## 4. Deploying

- **Normal path:** merge to `main` in each repo. Both services auto-deploy on commit.
- **Specific commit (used for staging verification):** `POST https://api.render.com/v1/services/<id>/deploys` with `{"commitId":"<sha>"}` using an API key from the authenticated Render CLI config. Never paste the key into chat or files.
- After a backend deploy: `curl https://backend-sq66.onrender.com/api/health` and confirm `release` equals the intended commit.
- After a frontend deploy, check:
  - `/`, `/login` and a deep link such as `/student/opportunities/1` return 200 (SPA rewrite `/* -> /index.html`).
  - The bundle contains `backend-sq66.onrender.com/api`.
  - A CORS preflight from `https://gotrainee-web.onrender.com` returns `access-control-allow-origin`.

## 5. Bootstrapping a Platform Admin

1. Register a normal account through the app with a strong password (preferably a dedicated admin email).
2. Set `ADMIN_PROMOTE_EMAILS=<that email>` on the Backend service and redeploy (or run `php artisan gotrainee:promote-admin <email>` where a shell exists).
3. Log in. The app routes admins to `/admin/dashboard`.
4. Remove the variable afterwards. Re-running it is harmless.

The public registration endpoint rejects `role=admin`.

## 6. Production database options (historical; Neon Free chosen on 2026-10-17)

The SRS specifies **MySQL**, and the old production data is MySQL 8 on Railway. Options:

| Option | Engine | Monthly cost (approx., check Render pricing) | Data migration |
|---|---|---|---|
| A. Render private service `mysql:8` + persistent disk | MySQL 8 (matches SRS and Railway) | Starter instance + disk (paid) | `mysqldump` from Railway, then `mysql` restore. Same engine, lowest risk |
| B. Render Postgres paid tier | PostgreSQL 16 | Basic tier (paid) | Requires MySQL to PostgreSQL conversion (e.g. pgloader) and validation |
| C. External managed MySQL (e.g. Aiven/TiDB free or paid) | MySQL-compatible | Free to paid | dump/restore; requires a new provider account (user action) |

The application is tested on SQLite, PostgreSQL 16 and MariaDB 10.4 (213 tests each), so A, B and C are all supported by code. Switching is configuration only (`DB_CONNECTION`, `DB_URL`).

**Recommendation:** A (or C) keeps MySQL and makes the Railway restore a straight dump and restore.

## 7. Recovering the Railway production data (blocked; needs owner approval)

Facts verified on 2026-10-16:

- Railway project `GoTraniee` (`09a9a528-…`) still has service `MySQL` (image `mysql:8`) and volume `mysql-volume`, about 182 MB used and in state `READY`.
- All Railway deployments were removed at 09:04 UTC on 2026-10-16. The workspace customer state is `INACTIVE` (credit exhausted). The MySQL container is not running, so the data cannot be dumped right now.
- Starting the MySQL service is a Railway deploy. The agent's attempt was blocked pending owner approval.

Procedure once the owner approves (keep credentials out of shell history and logs):

1. Re-activate Railway billing if required. Redeploy **only** the `MySQL` service with its existing volume, and do not redeploy the Railway backend.
2. Enable a TCP proxy for MySQL, or use `railway connect MySQL`.
3. Take a consistent dump into a local file that is not committed:
   `mysqldump --single-transaction --routines --triggers --set-gtid-purged=OFF --default-character-set=utf8mb4 <db>`
4. Validate the dump: file size, `-- Dump completed`, and per-table `SELECT COUNT(*)` on the source.
5. Restore into a disposable MySQL (local MariaDB on port 3317 works). Run `php artisan migrate:status` and the new forward migrations there. Compare row counts, check `users`, `project_requests`, `project_opportunities`, `project_applications` and `project_teams`.
6. Restore into the chosen production database (§6), run `php artisan migrate --force` (only forward migrations `2026_10_16_*` and `2026_10_17_000001_create_project_execution_tables` are pending relative to Railway's `4cf0791`; they only add tables and columns), then verify with `/api/health` and a login.
7. Point the Backend's `DB_CONNECTION`/`DB_URL` at it, deploy, and verify.
8. Stop Railway MySQL only after the new deployment is verified. Keep the dump file offline.

Never replace a populated database with an empty one, and never roll back migrations against production.

## 8. Rollback

- **Code:** in the Render dashboard, roll back to the previous deploy, or deploy the previous commit with the API call in §4. Both deploy histories are visible via `GET /v1/services/<id>/deploys`.
- **Schema:** forward-only. The 2026-10-16 and 2026-10-17 migrations only add tables and columns. Older code ignores them, so a code rollback does not need a schema rollback.
- **Frontend:** redeploy the previous commit. The static site has no state.

## 9. Known limitations of the free tier

- Free web services sleep after inactivity. The first request takes about 30–60 s to wake.
- No persistent disk. Uploaded profile images (`storage/app/public`) are lost on every redeploy or restart. Production needs a paid disk or object storage (S3-compatible `FILESYSTEM_DISK`).
- Free Postgres expires on 2026-11-15 and has no backups, so it cannot meet NFR-11 (daily backups). Production needs a paid database with backups.
- Google sign-in needs `https://backend-sq66.onrender.com/auth/google/callback` added to the OAuth client's authorized redirect URIs in Google Cloud Console. This is an owner action.
