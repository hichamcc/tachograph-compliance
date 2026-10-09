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

### Automatic refresh every 2 hours (recommended, works with `QUEUE_CONNECTION=sync`)

Add **one** cron job in the hosting panel:

```cron
0 */2 * * * cd /var/www/wttsystem.dk/tachograph && php artisan tacho:refresh >> /dev/null 2>&1
```

Every run it:
1. syncs the driver list from Mapon;
2. downloads the latest data for each driver — the first run fetches 4+ weeks per driver, later runs only the last few days;
3. re-checks the **current week** (on Monday–Wednesday also the previous week, because late card downloads change it) for drivers active in the last 5 weeks. The automatic report for a week is replaced, not duplicated; checks started by a user are kept;
4. deletes raw Mapon data older than 90 days.

Drivers without driving/work in the last 5 weeks (`TACHO_ACTIVE_WEEKS`) are refreshed only once a day and are hidden from the driver list by default. The command runs in the cron process (no queue, no request time limit) and skips itself if the previous run is still going. Run it once by hand after deploying to load the initial 4 weeks: `php artisan tacho:refresh`.

### Automatic refresh with a "Call URL" cron (hosts without command cron)

If the panel can only call a URL:

1. Create a secret token and put it in `.env` (at least 32 characters):
   ```bash
   php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
   ```
   ```env
   TACHO_CRON_TOKEN=<the printed value>
   ```
   then `php artisan config:cache`.
2. In the panel add a scheduled task → **Call up URL**, **every 5 minutes**:
   ```
   https://tachograph.wttsystem.dk/cron/refresh/<the same token>
   ```

Each call works for at most `TACHO_CRON_SECONDS` (25 s) on the drivers that are *due* (never downloaded; active and not refreshed for 2 h; inactive and not refreshed for 24 h), then stops; the next call continues. The first full load takes about an hour of calls, after that active drivers are refreshed about every 2 hours and most calls finish instantly. The response is only counts, e.g. `{"status":"ok","refreshed":12,"checked":12,"failed":0,"remaining":40,...}`.

Security: without `TACHO_CRON_TOKEN` (or with a wrong token) the URL returns 404; it is rate-limited and never returns driver data. Treat the token like a password — to change it, set a new value in `.env`, run `php artisan config:cache`, and update the panel.

### Without cron (`QUEUE_CONNECTION=sync`)

This is a supported mode: there is no queue and no cron, and everything runs during the request when someone clicks **Run check** or **Sync from Mapon**.

- **Nothing runs automatically.** There is no nightly sync or check; drivers are synced and checked from the UI (or `php artisan tacho:sync-drivers` / `tacho:fetch` over SSH).
- **A check waits for the download to finish.** A driver's first check downloads ~5 weeks of history (2 Mapon calls); later checks re-download only the last few days. The request asks PHP for up to 180 s; if the host caps `max_execution_time` lower (e.g. 30 s), keep periods short and set `MAPON_TIMEOUT=20`.
- **Mapon failures** (timeouts, rate limits) show as a *Failed* run with the reason; click **Run check** again.
- **Old raw Mapon responses** are deleted automatically (at most once a day, after a check) once they are older than `TACHO_RAW_RETENTION_DAYS` (default 90).
- The dashboard hides the queue and cron indicators.

## 6. Checks

- [ ] `storage/` and `bootstrap/cache/` are writable by PHP.
- [ ] `https://your-domain/.env` and `https://your-domain/../storage` return 404/403.
- [ ] Only HTTPS is used (enable HTTPS redirect in the panel).
- [ ] `php artisan tacho:discover --days=1` succeeds.
- [ ] Create users (public sign-up is disabled), then have each user enable 2FA:
  ```bash
  php artisan tinker --execute="App\Models\User::create(['name' => 'Name', 'email' => 'name@example.com', 'password' => 'a-long-password', 'email_verified_at' => now(), 'role' => 'member']);"
  ```
  Use `'role' => 'admin'` for administrators. To allow sign-up again, uncomment `Features::registration()` in `config/fortify.php`.
- [ ] After one minute, `php artisan queue:monitor database:default` or the `jobs` table shows the queue draining.

## Updating

Build locally (step 1), upload the changed files, then:

```bash
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
```
