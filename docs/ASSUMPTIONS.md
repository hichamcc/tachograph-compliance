# Assumptions & legal interpretations

This tool supports compliance monitoring. It does **not** make official legal decisions. Every interpretation below must be reviewed against the current Regulation (EC) No 561/2006, as amended by Regulation (EU) 2020/1054, and against applicable national guidance before operational use.

All thresholds are set in `config/tachograph.php`.

## Data source (Mapon)

| # | Assumption |
|---|---|
| D1 | `driver/daily_activities` (beta) is the only activity source. Raw DDD files are not parsed in v1. |
| D2 | The account rejects `include[]=card_events` / `work_place_events` with error 7 (confirmed 2026-10-05). As a result, **card in/out events are unavailable**, and card-out rest cannot be detected. The detection code exists and switches on automatically if the events start arriving. |
| D3 | REST with `source = unkn` is Mapon's gap filler. It becomes **UNKNOWN** and is never counted as rest. |
| D4 | Mapon's `duration` field is ignored. Duration is always `end - start`. |
| D5 | Mapon's `day` buckets (Europe/Copenhagen) are ignored. Only raw UTC timestamps are used. |
| D6 | Gaps of up to `gap_tolerance_seconds` (60 s) are closed. Mapon days end at `:59:59`, which leaves 1 s between days. Larger gaps become UNKNOWN. |
| D7 | Records from vehicle CAN data (`source = can`) are used but marked uncertain. Findings that depend on them are `POTENTIAL`. |
| D8 | Overlapping records: driver-card data beats CAN data, **except** that CAN driving, work or availability beats a card **REST**. The CAN record stays uncertain, so findings that depend on it are `POTENTIAL`. When two equally reliable records with different activities overlap, the overlap becomes UNKNOWN. |
| D8a | Reason for the D8 exception, confirmed on live data 2026-10-06: after the last driver-card download, Mapon fills the rest of the day (up to 21:59:59 UTC) with REST marked `ddd`. On days without any download, the whole day is filled this way. CAN data shows the real driving in those periods. Without the exception, that driving disappeared from the totals, so violations could be missed. |
| D8b | A gap filler (`unkn`) overlapped by real data is superseded. It is reported neither as an overlap nor as a "period without tachograph data". |
| D9 | Adjacent rest records are merged across vehicles and sources, because a rest is continuous wherever it was recorded. Driving and work are merged only when vehicle and source match. |

## Timeline & classification

| # | Assumption |
|---|---|
| T1 | Mapon does not distinguish breaks from rests. A continuous rest is classified by duration: **≥ 24h → weekly rest**, **≥ 9h → daily rest**, **otherwise → break**. |
| T2 | The 3h first part of a split daily rest is classified as a break. The daily-rest rule recognises the 3h + 9h pattern. |
| T3 | A shift (duty period) runs from the end of one daily or weekly rest to the start of the next. UNKNOWN never ends a shift. |
| T4 | Fixed week: Monday 00:00 to Monday 00:00 in `week_timezone`, which defaults to **UTC** (tachograph time). Confirm this against national practice. |
| T5 | Boundaries are **inclusive**. Reaching a limit exactly is compliant: e.g. 4h30 driving, a 45m break, 9h, 56h and 90h driving, a 9h reduced daily rest and a 24h reduced weekly rest. |
| T6 | Time after the end of the available data has not happened yet (or was not fetched). It is not treated as unknown. |

## Uncertainty

| # | Assumption |
|---|---|
| U1 | When UNKNOWN time could change the outcome, the finding is `INCOMPLETE_DATA`, never `VIOLATION`. |
| U2 | Breaks: an UNKNOWN block of ≥ 15 min could have been a break. A violation stays confirmed if 4h30 was already exceeded before that block, or again after it. |
| U3 | Daily driving: an UNKNOWN block of ≥ 9h could have been a daily rest that splits the shift. Driving plus unknown time over the limit also gives `INCOMPLETE_DATA`. |
| U4 | Weekly and two-week driving: unknown time, including time not covered by the fetched data, is assumed to be driving when checking whether the limit could be exceeded. |
| U5 | Incomplete data is never reported as a confirmed violation. |

## Rules

| # | Rule | Interpretation |
|---|---|---|
| R1 | Breaks | A break is ≥ 45 min, or ≥ 15 min followed later by ≥ 30 min, in that order. Driving between the two parts counts toward the 4h30. WORK does not count as a break. AVAILABILITY does not count by default (`availability_counts_as_break`). Daily and weekly rests also qualify as breaks. |
| R2 | Daily driving | Measured per shift. Over 9h is an *extended day* (reported as WARNING). Over 10h is a violation. A shift with more than 9h driving counts as one extension in the week where it starts. The 3rd extension in a week is a violation. |
| R3 | Daily rest | Only the part of the rest inside the 24h after the previous rest counts. A weekly rest that starts inside the window (with ≥ 9h of it in the window) satisfies the requirement. More than 3 reduced daily rests between two weekly rests is a violation. Before the first weekly rest in the data, the reduction count is a lower bound. |
| R4 | Weekly rest deadline | A weekly rest must start ≤ 6 × 24h after the end of the previous weekly rest. If the data contains no weekly rest at all, the deadline is counted from the start of the data. |
| R5 | Weekly rest pattern | In any two consecutive weeks there must be 2 regular weekly rests, or 1 regular + 1 reduced. A rest that straddles two weeks counts once, in the week holding its larger part (ties go to the earlier week). A long rest such as holidays counts as a regular weekly rest in **every** week holding ≥ 45h of it. |
| R6 | Compensation | A reduced weekly rest owes 45h minus its actual length. The compensation must be taken en bloc, after the reduced rest and before the end of the 3rd week following the week the rest is counted in. It must be attached to another rest: (a) a daily rest that lasts ≥ 9h + the owed time, or (b) a weekly rest that lasts ≥ 45h + the owed time. Each rest can compensate only one reduced rest. A reduced weekly rest is not itself treated as carrying compensation. |

## Cross-check against Mapon's counters (Phase 6, 2026-10-06)

`php artisan tacho:crosscheck --driver=ID` compares our values for the current fixed week with Mapon's live counters from `unit_data/driving_time_extended`. It compares weekly driving, previous-week driving, 10h extensions used, and reduced daily rests since the last weekly rest.

On a sample of 16 real drivers, 8 had live counters. Every driving-time and extension counter matched. Before the D8 fix, two drivers showed a weekly driving difference; that was the padded card-rest quirk. Two differences in **reduced daily rests** remain, and both are explained:

| Driver pattern | Mapon | Ours | Explanation |
|---|---|---|---|
| An 11h04 rest that started 13h after the previous rest ended, so only 10h58 falls inside the 24h window | regular | reduced | **Legal interpretation (R3):** we count only the part of the rest inside the 24h window, following the common enforcement reading that the daily rest must be completed within 24h. Mapon counts the whole rest. **To be confirmed by legal review.** |
| Overnight rest of 4h39 + 3h10, split by a 4-minute drive recorded only in CAN data | 1 reduced rest | insufficient rest (potential violation) | Mapon seems to ignore short CAN movements. We report the outcome as `POTENTIAL` because it depends on CAN data. Whether a vehicle movement of a few minutes interrupts a rest is a legal/operational question. |

Differences in the current week are also expected while card data has not been downloaded yet: Mapon's counters are live, while our data runs until the last fetch.

## Not evaluated in v1

These are listed in every report as "not evaluated":

- Ferry/train interruption of rest (Art. 9)
- Multi-manning (1-hour presence rule, 30-hour daily rest window)
- Two consecutive reduced weekly rests for international transport (2020/1054)
- Return home every 4 weeks
- Ban on taking a regular weekly rest in the vehicle
- National derogations and exemptions (Art. 13), and out-of-scope operations
- Manual entries beyond what Mapon returns
- Working Time Directive 2002/15/EC

## Open questions

1. Is the account's 2-week driving history always available? It depends on how often driver cards are remotely downloaded, since recent days may only exist as `unkn`.
2. Does Mapon offer any way to get card in/out events for this account?
3. `unit_data/driving_time_extended` returns live counters only for drivers currently assigned to a vehicle. In the sample, that was 8 of 16 drivers.
4. Should a long continuous rest that straddles weeks be split differently? See R5.
5. Must the daily rest be *completed* within 24h (our reading), or only *started* within 24h (Mapon's apparent reading)? See the cross-check section.
6. Does a short vehicle movement (a few minutes, recorded only in CAN data) interrupt a rest?
