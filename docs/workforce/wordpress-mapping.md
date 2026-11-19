# WordPress → HMS: what exists, how it maps, what is unresolved

Status: **inventory and mapping only. No data has been read, copied or changed.** This document was written from the WordPress plugins' source code (a copy on the office Mac under `Desktop/PrasanthiHospitals/wp-content/plugins/`). The WordPress database itself has not been looked at: no export of it was available.

## 1. What WordPress actually holds

Three custom plugins deal with staff:

| Plugin | What it does |
|---|---|
| `prasanthi-hospitals-staff-management` | The staff list. |
| `prasanthi-hospitals-staff-attendance` | Check-in and check-out, corrections with an audit trail, importing attendance from another source, monthly totals, a monthly "reviewed" sign-off, a one-time historical baseline. |
| `prasanthi-hospitals-staff-portal` | The reception role's menu and dashboard. No data of its own. |

**There is no leave, salary, payroll, payslip, advance, loan or expense code in any of them.** A search of all custom plugins, the theme and the handover document finds none. Either those are kept somewhere else (a spreadsheet, another plugin not in this copy, or paper), or they are not kept in WordPress at all. See "Unresolved" at the end; this decides what Phase 5 can migrate.

### Tables and stored options

| Source | Holds | Rows are |
|---|---|---|
| `{prefix}ph_staff` | `id`, `employee_id` (e.g. `PH-EMP-000001`, unique), `staff_name`, `designation`, `mobile`, `date_of_joining`, `employment_status` (`active` / `inactive`), `created_at`, `updated_at` | one per member of staff |
| `{prefix}ph_staff_attendance` | `id`, `staff_id` (→ `ph_staff.id`), `attendance_date`, `check_in`, `check_out` (nullable), `status` (always `present`), `created_by`, `updated_by`, `created_at`, `updated_at` | one per spell of work |
| `{prefix}ph_staff_attendance_audit` | `attendance_id`, `changed_by`, `changed_at`, `change_note`, old and new `check_in`, `check_out`, `attendance_date`, `status` | one per correction |
| `{prefix}ph_staff_attendance_import` | `source_key` (unique), `payload_hash`, `source_record_id`, `employee_id`, `staff_name`, `attendance_date`, `check_in`, `check_out`, `record_state`, `import_status`, `attendance_id`, `source_note`, `review_note`, `imported_by`, `imported_at` | one per row brought in from another source |
| `{prefix}ph_staff_month_reviews` | `month` (YYYY-MM), `review_hash`, `request_key` (unique), `reviewed_by`, `reviewed_name`, `reviewed_at`, `note`, `summary_json` | one per time a month was marked reviewed |
| option `ph_staff_baseline_v1` | `months`, `staff`, `records` (each with a state, seconds and source ids) | one saved historical baseline |

### Rules found in the code

1. **A day belongs to the day the shift started.** `attendance_date` is the shift-start date even when the check-out is after midnight. A day is "a person's shift-start date, never an hours-to-days conversion."
2. **One open spell at a time.** Checking in is refused while that person has a spell with no check-out. An inactive person's open spell can still be checked out.
3. **Check-out is never before check-in**, and a check-in is never in the future.
4. **Today's times can be edited by an administrator only**; an earlier day is changed through "History & corrections", which requires a note. Every correction writes the old and new values to the audit table.
5. **States of a record** (worked out, not stored): `completed` (both times, sensible), `open` (no check-out), `review` (times that do not make sense), and for imported rows not yet confirmed `missing_in`, `missing_out`, `pending`.
6. **Imported rows** are matched to saved attendance when the person, date and the times present agree with exactly one saved record; then they are "linked" evidence. Otherwise they stay pending. The saved attendance row "owns" confirmed dates, times and hours.
7. **Baseline records** have states `recorded`, `estimated`, `open`, `excluded`. An *estimated* day counts only when no actual record exists for that person and date: "actual records take precedence when an estimate shares the same date."
8. **Monthly figures** per person: completed spells, total seconds, open spells, pending, days (distinct dates with any record), recorded days.
9. **Times are stored as local wall-clock strings** in the site's timezone (displayed as Asia/Kolkata), not UTC.
10. **Permissions**: `ph_view_staff`, `ph_manage_staff`, `ph_manage_attendance`; editing today's saved times and imports need WordPress `manage_options` (administrator).
11. There are **no shifts, grace periods, late or early rules, overtime, half-days, weekly offs or holidays** in WordPress. "Shift" there means one spell of work.

## 2. Field mapping

Stable source identifiers: every imported row is linked in `wf_source_links` as (`wordpress`, entity, source id). Importing again finds the link and updates the same record; it never makes a second one.

### Staff: `ph_staff` → `wf_employees`

| WordPress | HMS | Rule |
|---|---|---|
| `id` | `wf_source_links.source_id` (entity `staff`) | The stable key. Not reused as our id. |
| `employee_id` | `employee_no` | Kept exactly (`PH-EMP-000001`). New staff continue the hospital's numbering. |
| `staff_name` | `name` | As is, trimmed. |
| `designation` | `designation` | As is. Empty → null. |
| `mobile` | `phone` | As is. Not validated against a pattern: the source did not. A value that is plainly not a number is quarantined for a person to read. |
| `date_of_joining` | `joined_on` | Null stays null. |
| `employment_status` | `status` | `active` → `active`; `inactive` → `inactive`. Inactive people are imported and kept, with all their attendance. |
| `created_at`, `updated_at` | `created_at`, `updated_at` | Kept. |
| — | `department_id`, `employment_type`, `shift_id`, `weekly_off`, `manager_id`, emergency contact, bank and identity details, salary | **Not in WordPress.** Left empty; to be filled by HR. Nothing is guessed. `employment_type` takes the column default `permanent` only if you approve that as a default; otherwise it is left for HR (see Unresolved). |

### Attendance: `ph_staff_attendance` → `wf_attendance`

| WordPress | HMS | Rule |
|---|---|---|
| `id` | `wf_source_links.source_id` (entity `attendance`) | Stable key. |
| `staff_id` | `employee_id` | Through the staff link. A row whose staff record is missing ("Staff record missing #n" in WordPress) is **quarantined**, not attached to anyone. |
| `attendance_date` | `work_date` | Same meaning: shift-start date. |
| `check_in`, `check_out` | `check_in`, `check_out` | Same wall-clock values, same timezone (see Unresolved 4). |
| `status` (`present`) | `state` | Worked out as WordPress does: no check-out → `open`; check-out before check-in → `review`; else `completed`. |
| — | `worked_minutes` | check-out minus check-in, whole minutes, for `completed` only. Breaks are not deducted for migrated rows: WordPress never did. |
| — | `late_minutes`, `early_minutes`, `overtime_minutes`, `shift_id` | Zero / null. WordPress had no shifts; none are applied backwards. |
| — | `origin` | `migrated`. |
| `created_by`, `updated_by` | — | WordPress user ids. Not mapped to HMS logins (different systems); kept in the staged copy and named in the import's audit line. |

### Corrections: `ph_staff_attendance_audit` → `wf_attendance_corrections`

Each audit row becomes a correction with status `applied`, `reason` = `change_note`, `before` and `after` built from the old and new columns, `decided_at` = `changed_at`. The WordPress user id is kept in `before`/`after` context, not mapped to an HMS login. An audit row whose attendance record is missing is quarantined.

### Import ledger: `ph_staff_attendance_import` → `wf_attendance_imports` + `wf_attendance_import_rows`

One import batch named "Carried over from WordPress" holds all rows. `source_key` and `payload_hash` are kept exactly, so the same punch arriving again later from the original source is still recognised as the same row. `import_status` `imported`/`linked` → row state `linked` with its `attendance_id`; anything else keeps its meaning (`missing_in`, `missing_out`, `pending` → `unmatched`). `source_note` and `review_note` → `note`.

### Month reviews and the baseline

| WordPress | HMS | Rule |
|---|---|---|
| `ph_staff_month_reviews` | `wf_audit_events` (action `attendance.month_reviewed`) | One audit line per review with month, reviewer name, time, note and the saved summary as `after`. It is history; nothing computes from it. |
| option `ph_staff_baseline_v1`, state `recorded` | compared against `wf_attendance` | Used for **reconciliation only**: the baseline's recorded days and seconds must equal what was imported. |
| baseline state `estimated` | **not imported as attendance** | An estimate is not a record. Estimated days are listed in the dry-run report with their totals, and stay out of attendance unless you decide otherwise (Unresolved 2). |
| baseline state `excluded`, `open` | reported | Listed in the dry-run report. |

## 3. What is quarantined instead of guessed

A row goes to quarantine (state `quarantined` on `wf_import_records`, with the reason) when:

- its staff record does not exist in the source;
- a date or time is not a real date or time;
- check-out is before check-in (`review` in WordPress) — imported as `review` only if you choose so, otherwise quarantined;
- two source rows claim the same person and the same check-in time;
- an employee number is empty or repeated;
- a mobile number is plainly not a phone number.

Quarantined rows are counted and listed in the dry-run report and are never silently dropped.

## 4. Reconciliation the dry run will produce

| Check | Source figure | HMS figure |
|---|---|---|
| Staff | count, and count by `employment_status` | `wf_employees` count by `status` |
| Attendance days by person and month | distinct `attendance_date` | distinct `work_date` |
| Attendance hours by person and month | sum of seconds for completed spells (the WordPress monthly build) | sum of `worked_minutes` × 60, to the minute |
| Open spells, review spells | counts | counts |
| Corrections | audit row count by month | corrections count by month |
| Import ledger | rows by `import_status` | rows by state |
| Baseline | recorded days and seconds per month | imported attendance for the same months |
| Leave, payroll, advances, expenses | **no source in WordPress** | reported as "nothing to reconcile" until a source is named |

The report also gives the snapshot's time and SHA-256, the row count of every source table, and every quarantined row with its reason. No production import happens until that report is approved.

## 5. Unresolved: decisions needed before Phase 5

1. **Where are salary, payroll, advances, loans and expenses kept today?** They are not in the WordPress code I was given. If they are in spreadsheets or registers, I need those files (or a decision to start payroll in the HMS from a chosen month with opening balances typed by the accountant). HMS expenses already exist under Front Desk → Expenses and are not affected.
2. **Estimated baseline days**: keep them out of attendance (my recommendation: an estimate is not a record), or import them marked as estimated?
3. **`review` spells** (check-out before check-in): import as they are, flagged for review, or quarantine?
4. **Timezone**: WordPress stores local wall-clock times. I will read the site's timezone setting from the snapshot and refuse to import if it is not Asia/Kolkata, unless told otherwise.
5. **A database export is needed**: a dump of the five sources above (or read-only database access) to stage a snapshot. I will not touch the live WordPress site; the export should be taken by whoever administers it.
6. **Default employment type** for the imported staff: leave empty for HR to fill, or treat everyone as permanent until changed?
7. **WordPress user ids** (who checked someone in): keep as numbers in the staged copy only, or do you want them matched to named HMS logins by hand?
