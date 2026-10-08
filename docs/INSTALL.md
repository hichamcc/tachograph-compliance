# Installation (local development)

Requirements: PHP 8.2+ (8.3/8.4 recommended), Composer, Node 20+, MySQL/MariaDB (or SQLite).

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```env
DB_DATABASE=tachograph
DB_USERNAME=...
DB_PASSWORD=...

MAPON_API_KEY=...            # restricted read-only key
MAPON_AUTH_MODE=header
MAPON_AUTH_HEADER=key

QUEUE_CONNECTION=sync        # local: jobs run inside the request
TACHO_ALLOW_IMPORT=true      # local: enables the JSON upload form
```

```bash
php artisan migrate
composer dev                 # server + queue + logs + vite
```

Do **not** run `php artisan config:cache` locally. Cached config makes the tests ignore `phpunit.xml`.

## First steps

```bash
php artisan tacho:discover                 # checks the key, prints the data-source mix
php artisan tacho:sync-drivers             # loads drivers and vehicles
php artisan tacho:fetch --driver=<ID> --start=2026-09-28 --end=2026-10-04
```

Open `/app/tachograph/drivers` in the browser.

## Offline / anonymized data

```bash
php artisan tacho:import-file tests/Fixtures/activities/week_happy_path.json
php artisan tacho:import-file tests/Fixtures/mapon/daily_activities_sample.json --format=mapon --driver=test_driver_01
php artisan tacho:evaluate --driver=test_driver_01 --start=2025-09-22 --end=2025-10-05 --format=json,csv,html --output=storage/app/private/reports/sample.json
```

## Tests

```bash
php artisan test
```

They use in-memory SQLite and faked HTTP; no Mapon key is needed.

## Commands

| Command | Purpose |
|---|---|
| `tacho:discover [--driver=] [--days=30]` | Probes the API and summarises the status/source mix |
| `tacho:sync-drivers` | Syncs drivers and vehicles from Mapon |
| `tacho:fetch --driver=ID… \| --all  --start= --end= \| --since=  [--no-evaluate]` | Queues the fetch jobs, then the evaluation |
| `tacho:evaluate --driver= --start= --end= [--format=json,csv,html] [--output=]` | Evaluates stored data and writes the report |
| `tacho:import-file <path> [--format=local\|mapon] [--driver=]` | Imports a JSON file |
| `tacho:crosscheck --driver=ID…` | Compares this week with Mapon's live counters |
| `tacho:prune-raw [--days=90]` | Deletes old raw responses |
