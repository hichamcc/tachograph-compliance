# Deployment to shared hosting

Shared hosting has no long-running workers, short execution limits and often no Node.js. The app is designed for this:
- **Jobs are small:** one driver × at most 28 days per job.
- **The queue is processed in bursts** by the scheduler, from a single cron entry.
- **Assets are built locally** and uploaded.

## 1. Build locally

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # creates public/build
```

## 2. Upload

- Upload the project **outside** `public_html`, e.g. `/home/USER/tachograph`.
- Point the (sub)domain's document root to `/home/USER/tachograph/public`. If the panel does not allow that, replace `public_html` with a symlink to `public/`.
- Do not upload `node_modules/`, `tests/`, `.env`, or any local `storage/app/private/*` and `storage/logs/*`.

## 3. Database & `.env`

Create a MySQL database in the control panel, then create `.env` on the server:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain
APP_KEY=                       # php artisan key:generate (once)

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

QUEUE_CONNECTION=database
SESSION_DRIVER=database        # or file
CACHE_STORE=database           # or file

MAPON_API_KEY=...
MAPON_AUTH_MODE=header
MAPON_AUTH_HEADER=key

TACHO_TIMEZONE_DISPLAY=Europe/Copenhagen
TACHO_WEEK_TIMEZONE=UTC
TACHO_ALLOW_IMPORT=false

MAIL_MAILER=smtp               # needed for e-mail verification / password reset
```

## 4. Initialise

Run these over SSH, or from the panel's terminal:

```bash
php artisan key:generate --force      # first deploy only
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`php artisan storage:link` is **not** needed. Reports and raw data must stay private.

If the host has no shell at all, use a host with at least a cron or terminal feature. The app cannot be initialised safely from the web.

## 5. Cron (one entry)

```cron
* * * * * cd /home/USER/tachograph && php artisan schedule:run >> /dev/null 2>&1
```

This drives:

| When | Task |
|---|---|
| every minute | `queue:work --stop-when-empty --max-time=50` |
| 02:45 | `tacho:sync-drivers` |
| 03:00 | `tacho:fetch --all --since="-7 days"` (incremental; the first run per driver fetches 28 days of history) |
| daily | prune failed jobs and finished batches |
| weekly | `tacho:prune-raw --days=90` |

Use the PHP CLI binary matching the web PHP version (e.g. `/usr/local/bin/php83`) if `php` points to another version.

If cron is not available, set `QUEUE_CONNECTION=sync` and keep report periods short (one driver, one week), so a request fits in `max_execution_time`.

## 6. Checks

- [ ] `storage/` and `bootstrap/cache/` are writable by PHP.
- [ ] `https://your-domain/.env` and `https://your-domain/../storage` return 404/403.
- [ ] Only HTTPS is used (enable HTTPS redirect in the panel).
- [ ] `php artisan tacho:discover --days=1` succeeds.
- [ ] Register the first user, verify the e-mail, enable 2FA, and make the user an admin if needed (`users.role = 'admin'`).
- [ ] After one minute, `php artisan queue:monitor database:default` or the `jobs` table shows the queue draining.

## Updating

Build locally (step 1), upload the changed files, then:

```bash
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
```
