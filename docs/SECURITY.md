# Security & personal data

## Mapon API key

- The key lives **only** in `.env` on the server (`MAPON_API_KEY`). `.env` is git-ignored, and `.env.example` contains a placeholder.
- Use a **restricted key** in Mapon, limited to read access to: `driver/list`, `driver/daily_activities`, `unit/list`, `company/get` and (optionally) `unit_data/driving_time_extended`.
- The key is sent as a request header (`key:`), so it never appears in URLs.
- Every Mapon error is rethrown as a sanitized `MaponException`: no URL, no key, no chained exception. Tests confirm the key never appears in error messages, for both header and query auth.
- The `tachograph` log channel runs `App\Logging\RedactSecrets`. It strips the key and any `key=` query value from messages and context.

### Rotating the key

1. Create the new key in Mapon (with the same restrictions).
2. Replace `MAPON_API_KEY` in `.env` on the server.
3. Run `php artisan config:cache`, or `php artisan config:clear` if config is not cached.
4. Check it: `php artisan tacho:discover --days=1`.
5. Revoke the old key in Mapon.

No code change is needed.

## Personal data

| Data | Stored | Notes |
|---|---|---|
| Driver ID (Mapon) | yes | Used internally |
| Driver name | yes (`display_name`) | Display only. Never logged. |
| Driver card number | **hash only** (`HMAC-SHA256` with `APP_KEY`) | |
| E-mail, phone (from `driver/list`) | **no** | The raw driver list is never saved |
| GPS / location (from `unit/list`) | **no** | Not requested for compliance. The raw unit list is never saved. |
| Activity records | yes | Needed for the evaluation and as evidence |
| Raw activity responses | yes, 90 days | `storage/app/private/mapon/raw/`, deleted by `tacho:prune-raw` |
| Reports | yes | `storage/app/private/reports/`, not web-accessible |

- **Logs** contain processing IDs, endpoint names, counts and a pseudonymous driver hash (`Driver::logId()`). They never contain keys, URLs, names, card numbers or locations.
- **Development and tests** use anonymized fixtures only (`test_driver_01`, vehicle IDs `9000xx`, timestamps shifted by 52 weeks).
- **No external services** receive driver data: there are no analytics, and reports have no CDN assets. The downloadable HTML report inlines its CSS.

## Application access

- All tachograph pages are behind login (Fortify) and e-mail verification.
- **Enable two-factor authentication for every user:** Settings → Two-factor.
- The JSON upload form is disabled unless `TACHO_ALLOW_IMPORT=true`. Keep it `false` in production.
- Exports include formula-injection protection for spreadsheet cells.
- Sync and run creation are rate limited (5 and 10 per minute per user).

## Hosting

- The document root must be `public/`. `storage/` and `.env` must not be reachable over the web.
- Use HTTPS only. Set `APP_DEBUG=false` in production.
