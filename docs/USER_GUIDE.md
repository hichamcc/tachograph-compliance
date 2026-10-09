# User guide

This tool checks drivers' tachograph data from Mapon against the EU driving and rest-time rules (Regulation 561/2006). It **supports** compliance work; it is not an official legal determination.

## Drivers

**Tachograph → Drivers** lists every driver, with the time their data runs until, their last evaluation and its violation count.

- **Sync from Mapon** refreshes the list. Drivers removed in Mapon are marked *Inactive*.
- Use the search box to find a driver by name or ID. Search covers all drivers.
- By default the list shows drivers who drove or worked in the last **5 weeks**. **Show inactive (n)** shows the others too (greyed out); drivers removed in Mapon are marked *Removed in Mapon*.

## Running a check

1. Open a driver.
2. Choose the **first** and **last day**. The default is last week, Monday to Sunday.
3. Click **Run check**.

The app downloads the driver's data from Mapon, including 4 weeks of history that the two-week and weekly-rest rules need, and checks it. This takes a few seconds (longer for a driver's first check). When it says *Finished*, click **View report**. If it says *Failed*, Mapon could not be reached; try again.

The app also refreshes itself every 2 hours: it downloads the newest data for all drivers and re-checks the current week, so the driver list and dashboard are up to date without clicking anything. A check you start yourself is kept as its own report.

## Reading the report

**Summary.** Counts of confirmed violations, potential violations, incomplete data and warnings. It also shows total driving, work, availability, break, rest and unknown time, and how far the data reaches.

**Violations.** Each row shows:
- the rule
- the exact time interval
- the measured value against the limit
- an explanation
- the number of tachograph records it is based on (hover to see their IDs)

**Status meanings:**

| Status | Meaning |
|---|---|
| **Violation** | The recorded data breaks the rule. |
| **Violation (potential)** | It breaks the rule, but the result depends on less reliable data: vehicle CAN data instead of the driver card, or rest taken with the card removed. Check before acting. |
| **Incomplete data** | Data is missing, and the missing time could change the result. This is **never** a confirmed violation. |
| **Warning** | Allowed, but worth knowing: an extended 10h driving day, or weekly-rest compensation that is still due. |
| **Compliant** | The rule is met. Exact limits count as compliant, e.g. exactly 4h30 driving or exactly 9h rest. |

**Shifts.** One row per duty period, from the end of one daily rest to the start of the next. It shows driving, work, breaks, unknown time and the rest that followed, with the status of daily driving, breaks and daily rest (regular, reduced, split, or insufficient). A shift can cross midnight; daily limits apply per shift, not per calendar day.

**Weeks.** Fixed weeks from Monday 00:00 to Sunday 24:00 (UTC). Each row shows:
- driving against 56h
- the two-week total (limit 90h)
- extended driving days used (out of 2)
- reduced daily rests
- weekly rests (regular ≥ 45h, reduced ≥ 24h)
- compensation status for reduced weekly rests (completed, pending or overdue)

**Data quality.** Gaps without tachograph data, overlapping records, CAN-only data, and records that could not be read. These are never counted as violations, but they explain *Incomplete data* results.

**Not evaluated.** Rules this version does not check: ferry/train, multi-manning, return home every 4 weeks, two consecutive reduced weekly rests, rest in the vehicle, national exemptions, and the Working Time Directive.

## Times

Times are shown in **Danish time** (Europe/Copenhagen). Hover over a time to see it in UTC, which is the time the tachograph uses. Weeks are calculated in UTC.

## Exports

At the top of a report:

| Export | Contents |
|---|---|
| **JSON** | The full report, for other systems |
| **Findings CSV** | One row per finding |
| **Shifts CSV** | One row per shift |
| **Weeks CSV** | One row per week |
| **Activities CSV** | Every activity in the period (the evidence) |
| **HTML** | A self-contained file to archive or print |

CSV files open directly in Excel.

## Why does data stop before today?

Driver-card data reaches Mapon only when the card is downloaded remotely. Until then, Mapon has only vehicle (CAN) data or nothing for recent days. Recent days therefore often show *Unknown* time or *potential* results. Re-run the check after the next download.
