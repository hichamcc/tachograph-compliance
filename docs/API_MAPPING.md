# Mapon API → internal model

Everything below was confirmed against the live account on 2026-10-05 and 2026-10-06, unless marked *docs*.

## Connection

| Item | Value |
|---|---|
| Base URL | `https://mapon.com/api/v1/` (`MAPON_BASE_URL`) |
| Auth | Request header **`key: <api key>`** (`MAPON_AUTH_MODE=header`, `MAPON_AUTH_HEADER=key`). Query-string `?key=` also works but puts the key in URLs. `X-API-Key` and `Authorization` are rejected (error 1004). |
| Format | `<action>.json`, e.g. `driver/list.json` |
| Response wrapper | Usually `{"data": ...}`. `driver/daily_activities` returns a **bare list of days**. The client accepts both. |
| Errors | `{"error": {"code": N, "msg": "..."}}`, sometimes with HTTP 200. Retried: 1011 (rate limit), HTTP 5xx and connection errors. Not retried: everything else, e.g. 1004/1005 (key), 1015 (paid add-on), 7 (invalid include). |
| Limits | 5 concurrent requests (*docs*). Max 31 days per activity request; we use 28-day chunks. |

## Endpoints used

| Endpoint | Used for | Stored? |
|---|---|---|
| `company/get` | Account timezone (`data.companies[0].timezone` = Europe/Copenhagen). Used only by `tacho:discover`. | Raw response, by discovery only |
| `driver/list` | Driver sync | **No.** It contains e-mails and phone numbers. Only `id`, `name + surname` and an HMAC of `tacho` (card number) are kept. |
| `unit/list` | Vehicle sync (`unit_id`, `label`) | **No.** It contains GPS positions. |
| `driver/daily_activities?driver=&from=&till=` | Activity data | Yes, every chunk is saved under `storage/app/private/mapon/raw/` (pruned after 90 days) |
| `unit_data/driving_time_extended?unit_id=` | Cross-check only | No. Only the numeric counters are read. The response also contains names and card IDs, which are ignored. |

`include[]=card_events` / `include[]=work_place_events` are **rejected** for this account (error 7). Card in/out events are therefore not available.

## `driver/daily_activities` → `Activity`

```json
[{ "day": "2026-09-07T22:00:00Z",
   "summary": { "shift": 0, "driving": 0, "rest": 86399 },
   "activities": [ { "start": 1788818400, "end": 1788904799, "duration": 86399,
                     "status": "REST", "source": "ddd", "unitId": 825663 } ] }]
```

| Mapon field | Internal | Notes |
|---|---|---|
| `start`, `end` (Unix, UTC) | `Activity::start`, `end` | The only time source used |
| `duration` | ignored | Recomputed as `end - start` |
| `day`, `summary` | ignored | `day` buckets start at 22:00 UTC (Copenhagen midnight) |
| `status` | `type` | DRIVING → DRIVING, WORK → WORK, AVAILABLE → AVAILABILITY, REST → REST (classified later as BREAK, DAILY_REST or WEEKLY_REST). CARD_* and WORK_PERIOD_* become events. Anything else becomes UNKNOWN plus an `INVALID_ACTIVITY_TYPE` issue. **Seen in live data: `"0"` (CAN).** |
| `source` | `source` | `ddd` = driver card (authoritative), `can` = vehicle CAN (uncertain), `unkn` = gap filler (REST/unkn becomes **UNKNOWN**, never rest) |
| `unitId` | `vehicleId` | Mapon unit ID |
| — | `sourceEventIds` | `sha1(driver\|start\|end\|status\|unit)`, used as the dedupe key and as evidence in findings |

## Observed quirks and how they are handled

| # | Quirk | Handling |
|---|---|---|
| 1 | There is no BREAK status; breaks arrive as REST. | `RestClassifier` classifies by duration. |
| 2 | Gaps are filled with REST/`unkn`. | These become UNKNOWN and are never counted as rest. |
| 3 | Days end at `hh:59:59`, leaving a 1 s gap to the next day. | Gaps ≤ 60 s are closed, and rests are merged across midnight. |
| 4 | Requests are snapped to whole days, and chunk edges overlap. | Exact duplicates are dropped by the dedupe key. |
| 5 | `duration` can be 0 for a real span (*docs*). | Duration is always computed from the timestamps. |
| 6 | **After the last card download, the day is padded with REST/`ddd`** up to 21:59:59. Days with no download are entirely REST/`ddd`. CAN shows the real driving at those times. | CAN driving, work or availability beats card REST (marked uncertain). See ASSUMPTIONS D8. |
| 7 | A gap filler (`unkn`) sometimes overlaps real `ddd` data after a later download. | Real data wins, and no data-quality warning is reported. |
| 8 | Very short CAN records (minutes) can split a rest. | Kept as recorded. Findings that depend on them are `POTENTIAL`. |

## `driving_time_extended` (cross-check)

Keyed by driver slot (`driver1`, `driver2`) for the vehicle's current drivers. Fields read: `driver_id`, `week.driving` (s), `week.previous_week_driving` (s), `week.10h_driving_extensions_used`, `week.9h_rest_shortening_used`. Live counters exist only for drivers currently assigned to a vehicle.
