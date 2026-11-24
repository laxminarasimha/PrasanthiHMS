# Workforce, Attendance, Payroll and Expenses

Replaces the staff attendance kept in WordPress, and adds leave, payroll, advances and an approval flow for expenses, inside the HMS. Built in five phases, each its own pull request. **This document covers Phase 1: the foundation.** Nothing here is deployed, merged or run against live data without explicit approval.

Companion documents: [WordPress mapping](wordpress-mapping.md) · [Permission matrix](permission-matrix.md)

## Phases

| Phase | Contents | State |
|---|---|---|
| 1 | Data model, permissions, audit base, tenant wall, password step, WordPress mapping | **This pull request** |
| 2 | Staff directory, shifts, holidays, attendance (daily, monthly, corrections, sheet import), leave | **Built, in review** (see "Phase 2" below) |
| 3 | Salary structures, payroll runs, payslips, advances and loans | Done (see below) |
| 4 | Expense approval flow on the existing expenses, categories, recurring templates, reports, no double counting | Not started |
| 5 | WordPress snapshot, staging, dry-run reconciliation report, cutover tools | Not started; needs a database export |

Phase 1 adds no screens of its own except the longer, grouped permission list under Setup → Staff. It changes no existing workflow.

## Phase 2: staff, shifts, attendance and leave

Screens (menu tab **Workforce**, or **My record** for staff who only have their own): Today, Staff, Attendance (Day, Month, Corrections, Bring in a sheet), Leave (Requests, Balances), Setup, My record. Server routes are in `backend/routes/workforce.php`; the arithmetic is in `AttendanceRules` and `LeaveBook`, which payroll will read in Phase 3 so the two can never disagree.

Rules as built (each is a setting or a shift's own value, not fixed in the program):

- **A day belongs to the day the shift started**, as in WordPress. A night shift's check-out the next morning is the same day.
- **One open spell at a time**; a check-out is never before its check-in; nothing is entered for a time that has not come. Entering a day other than today needs a reason.
- **Lateness** is counted from the shift's start once past its grace; **early leaving** from its end once past its allowance; the **unpaid break** comes off only when the spell is at least half the shift long; **overtime** is what is worked beyond the shift's overtime mark.
- **Full day, half day, absent** come from the shift's two marks. A day someone judged by hand keeps that judgement. With no marks set, any time in is a full day.
- **A missing check-out** leaves the day standing and flagged until someone puts the time right; it is listed on the Today page.
- **Every day splits into present + leave + absent = 1** on a working day. Weekly offs and holidays are neither.
- **Corrections**: changing a saved entry directly needs `wf:attendance:edit` and a reason; anyone else asks, and nothing changes until someone with `wf:attendance:approve` (within reach) approves. Nobody decides a correction to their own attendance. Both paths keep before and after.
- **Sheets**: the browser reads a CSV (columns found by name: employee number, date, check in, check out); the server judges every line (ready, already here, no such staff, no check-in, no check-out, cannot be read) and saves **no attendance** until "Bring in" is pressed. Each line has a fingerprint, so the same line is never brought in twice, in this sheet or a later one.
- **Leave** is counted in working days: weekly offs and holidays inside a stretch are not leave unless the hospital chooses to count them, and attendance shows those days as days off accordingly. Half days at either end count a half.
- **Earning leave**: a yearly kind is given whole at the start of the leave year, in proportion for someone who joined part-way; a monthly kind a twelfth a month. A month is earned by someone on the staff on its first day. Each line can be written once only.
- **Year end**: what is left is carried up to the kind's limit and the rest lapses, both as ledger lines; the old year ends at nothing. Carried leave unused within the kind's months lapses, with carried days counted as used first.
- **Approval** takes the days from the account and is refused beyond the balance unless the hospital allows a negative balance. **Cancelling** approved leave gives the days back as a line of their own. Nobody decides their own leave.
- **Self check-in is off by default.** When switched on it records only the login and the time: no location, no biometric.
- **Exports** (staff, month attendance, leave) are made on the server, need their own permission, never include bank, identity or pay details, guard against spreadsheet formulas, and write an audit event.

Front-end tests now exist (`npm test` in `frontend/`, Vitest with a pretend browser): the sheet reader, the password-again step, and the Today page's loading, error and empty states. Phone-width layout cannot be measured in a pretend browser; each screen was checked by hand at 375px with made-up staff (no sideways scrolling on any of the ten views).

## Decisions

### Where the module lives
`backend/app/Workforce/` (models, support, HTTP), `backend/routes/workforce.php` (loaded under `/api/workforce`), tables prefixed `wf_`. It depends on the HMS for logins, branches, the department list, the expenses table and the print/branding conventions, and nothing in the HMS depends on it. Nothing in it names this hospital.

### Tenant = branch, enforced on the server
The HMS already has `branches` and puts `branch_id` on users and bills. Every `wf_` table that belongs to a hospital carries `branch_id`. `BranchScoped` (a trait on every such model) narrows **every** query to the signed-in person's branch and stamps new records, so no screen or request can read or write across hospitals. Someone signed in with no branch sees nothing rather than everything. A job with nobody signed in (the importer, a test) must name its branch with `Tenant::run()`.

Honest limit: the HMS's department list (`admin_entries`) is hospital-wide today, not per branch. With one branch this is harmless; before a second hospital shares one database, that list needs a branch column. Staff, attendance, leave and pay are walled regardless.

### Employee ≠ login
`wf_employees` is the staff directory. Most employees never sign in, so an employee is not a `users` row; the two are linked (`user_id`) when both exist. Self-service works through that link. An employee who leaves is marked inactive and kept with all history; nothing is deleted.

### Sensitive fields
Bank account, IFSC, UPI, PAN, Aadhaar, UAN and ESI numbers live in a separate table (`wf_employee_private`) that ordinary lists never load, are encrypted at rest (Laravel's `encrypted` cast, keyed by `APP_KEY`), are hidden from any model-to-array conversion, need their own permissions (`wf:staff_private:view` / `:edit`), and are written to the audit trail only as masked values (last four characters). Salary amounts are protected by permission (`wf:salary:view`), not encrypted, because payroll must add them up.

### Ledgers, never overwritten balances
Leave and advances each have an append-only ledger (`wf_leave_ledger`, `wf_advance_ledger`). A balance is the sum of its lines. The models refuse update and delete; a mistake is corrected by another line. `once_key` on the leave ledger stops the same accrual or expiry being written twice.

### Payroll that cannot be quietly changed or run twice
- Lifecycle: draft → reviewed → approved → finalised → paid, plus reversed. From *finalised* the models refuse any change to figures, lines or status except "paid", "reversed" and the payment details.
- Duplicate prevention is in the database: `wf_payslips` has a unique key on (employee, `live_period`). A standing payslip holds its period there; reversing empties it, freeing the month for a corrected run while the reversed payslip stays on record.
- Every payslip line stores an `explanation`: how the amount was arrived at, in words and figures.
- Salary structures are effective-dated; a rise is a new row.
- The system records payment mode, date and reference. It never sends money anywhere.

### Statutory parts are off and empty
PF, ESI, professional tax and TDS exist only as salary components the accountant may add. They start disabled with no rule. **No rate, ceiling or slab is written anywhere in this code.** A test asserts that nothing is seeded.

### Audit
`wf_audit_events` is separate from the HMS's existing `audit_logs` because it needs what that table lacks: tenant, reason, and before/after values. It is append-only (the model refuses update and delete; there is no route that deletes). Each line carries a SHA-256 of itself and of the previous line, so `Audit::verify()` reports the first line that was altered or removed directly in the database. Sensitive reads (opening bank details, exporting payroll) will be recorded as events from Phase 2 on.

### Password again for sensitive steps
`POST /api/workforce/confirm-password` opens a ten-minute window, per sign-in, checked by the `RequireFreshPassword` middleware (answers 428 otherwise). Phases 3 to 5 put it on payroll finalising and reversal and on sensitive exports. A wrong password is recorded.

### Permissions
The module's permissions are `wf:<what>:<action>` with the eight actions asked for. They join the HMS's existing permission list, so the same Staff screen, the same per-person ticks, the same rules apply: an untick always beats the role; nobody changes their own; nobody gives more than they hold; sensitive ones are the admin's to give; every change needs a reason and is recorded. Permissions are read on every request, so a change takes hold at once.

Each role preset also sets a **reach**: all staff, the person's own departments (`wf_department_managers`), or their own record. `Access::narrow()` applies it to any query on the server.

Three roles are added: HR and Payroll, Accountant, Department Manager. Outside the module they start with nothing (the Accountant also sees HMS reports and may record expenses, as accountants do today). No single role can take a payroll from draft to finalised: HR prepares, the Accountant approves, the admin finalises.

### Expenses (Phase 4 plan)
The HMS already has expenses (Front Desk → Expenses) tied into the day close and the organisation report. Phase 4 **extends that table** with status, category, cost centre, tax and attachments rather than building a second expense system, and existing entries become "paid" without change. Payroll is posted to the accounts as **one** expense entry per run (`wf_payroll_runs.posted_expense_id`), in a category marked `is_payroll`; entries typed by hand in that category are then refused or flagged, so salaries are not counted twice. Phase 1 does not touch the expenses table.

## Libraries considered

| Need | Considered | Decision |
|---|---|---|
| Money arithmetic | `brick/math` (already installed with Laravel), `brick/money`, `moneyphp/money` | Use `brick/math` `BigDecimal` for all payroll sums and rounding; amounts stored as `DECIMAL`. No new dependency. `brick/money` adds currency handling the HMS does not need (single currency). |
| Payroll rules (PF, ESI, PT, TDS) | No maintained, trustworthy open-source PHP library for Indian statutory payroll was found; hosted payroll APIs exist but would send salary data to a third party | Configurable components with accountant-entered rules. Rates are not embedded. |
| Holiday calendars | `spatie/holidays`, `azuyalabs/yasumi` | Not used: hospital holidays are local decisions, not national lists. A plain configurable calendar table. Either library could pre-fill a calendar later. |
| Recurring expenses | `rlanvin/php-rrule`, `simshaun/recurr` | Not needed: templates are monthly with a day of the month. |
| Calendar views | The HMS's own month and day views | Reuse; no new front-end dependency. |
| Payslip documents | `barryvdh/laravel-dompdf`, `spatie/laravel-pdf` (needs a headless browser, not available on the shared host) | Follow the HMS convention: a branded print page (browser "Save as PDF"). dompdf is the fallback if payslips must be generated on the server (for example to email them). |
| CSV import/export | `league/csv`, `maatwebsite/excel` | The HMS's existing CSV helpers; `league/csv` if sheet parsing grows. |

## Schema added (29 tables, all new)

Foundation: `wf_settings`, `wf_audit_events`, `wf_import_snapshots`, `wf_import_records`, `wf_source_links`.
Staff and time: `wf_shifts`, `wf_holiday_calendars`, `wf_holidays`, `wf_employees`, `wf_employee_private`, `wf_department_managers`, `wf_attendance`, `wf_attendance_corrections`, `wf_attendance_imports`, `wf_attendance_import_rows`.
Leave: `wf_leave_types`, `wf_leave_requests`, `wf_leave_ledger`.
Pay: `wf_salary_components`, `wf_salary_structures`, `wf_salary_structure_lines`, `wf_payroll_runs`, `wf_payslips`, `wf_payslip_lines`.
Advances: `wf_advances`, `wf_advance_schedule`, `wf_advance_ledger`.
Expense setup: `wf_expense_categories`, `wf_expense_templates`.

No existing table is altered. The three migrations apply and roll back cleanly on MySQL and SQLite.

## Backup and restore

- The HMS backup (Setup → Backups) dumps the whole database, so the `wf_` tables are included with no extra step.
- **The encrypted fields can only be read with the same `APP_KEY`** that wrote them. A database restored onto a server with a different key will open, but bank and identity numbers will be unreadable. Keep a copy of `backend/.env`'s `APP_KEY` with the backups, stored separately and safely. Never rotate the key without re-encrypting those columns first.
- After a restore, run the audit check (`GET /api/workforce/audit/verify`, or `Audit::verify($branchId)`); it should report every line intact.
- Receipts and other attachments (Phase 4) will be stored with the HMS's other uploaded files and are covered by "Copy everything now".

## Compliance assumptions (to confirm with the hospital's accountant)

1. Statutory deductions apply only as the accountant configures them; the system makes no claim of legal correctness for any rate.
2. The system records statutory filing and bank-transfer status; it does not file or transfer.
3. Leave year, salary month and rounding (to the rupee, or to paise) are settings, undecided until Phase 3.
4. Staff personal data is processed for employment purposes; retention of ex-employees' records follows the hospital's policy (records are kept, not deleted).
5. Attendance check-in from a phone is **not** built. If wanted later it will use the staff login only, with no location or biometric tracking unless explicitly asked for.

## Decisions needed from the owner

See also the seven items at the end of the [WordPress mapping](wordpress-mapping.md).

1. Doctors start with self-service only in this module. If the owner works from a doctor login, payroll would need the admin login. Is that right?
2. Is "HR prepares, Accountant approves, Admin finalises" the separation you want?
3. Should the Hospital Manager see individual salaries? Today: no (run totals only).
4. Rounding of net pay, the salary month (calendar month?) and the leave year (January or April?).
5. Which month payroll starts in the HMS, and whether opening leave balances and outstanding advances will be typed in or come from a file.

## Tests

Phase 2 adds `WorkforceTimeAndLeaveRulesTest`, `WorkforceStaffAndAttendanceApiTest`, `WorkforceLeaveAndOverviewApiTest` (18 tests) and 14 front-end tests. Phase 1: `WorkforceFoundationTest`, `WorkforcePermissionsTest` (15 tests): tenant isolation, the no-branch case, department and self reach, encryption at rest and masking, audit append-only and tamper detection, one payslip per person per month, finalised-payroll immutability and reversal, append-only ledgers, idempotent source links, the password step, no invented statutory values, role presets, explicit deny, no self-escalation, sensitive grants. All synthetic data.

## Phase 3: salaries, payroll, payslips, advances and loans

Screens: **Salaries** (who is paid what; parts of a salary), **Payroll** (runs, one run from draft to paid, a payslip beside it), **Advances** (requests, the plan, the account), the printed payslip (`/workforce/payslips/:id/print`, on the hospital's print heading), and payslips and advances in **My record**. Payroll choices are under Setup → Payroll.

- **Arithmetic**: `PayrollEngine` works one payslip out from the person's days (read through `AttendanceRules`, so payroll and attendance never disagree), the salary in force, what is due on advances and any one-off lines. All money is `brick/math` decimals (`Money`), rounded to paise half up; net pay is rounded to the rupee or left in paise, by setting, with the difference as its own line. Every line carries how it was arrived at, in words and figures.
- **Unpaid days**: absent days and leave without pay take a day's pay off the earnings marked "shrinks with unpaid days"; a day's pay is the month's set pay ÷ the days in the month, 30 or 26 (setting). "Take pay off for absence" can be switched off while attendance is not yet kept here every day. Someone who joined or left mid-month is paid for the days employed.
- **Statutory parts**: worked out only from the rule the accountant enters (percentage, ceiling, "only for pay up to", fixed amount, slabs, rounding). With no rule, nothing is taken and the payslip says so. No rate is built in.
- **The run**: draft → reviewed → approved → finalised → paid; reversed only after finalising. Whoever prepared or reviewed a run cannot approve it (unless admin). Each step forward first works the run out again and refuses if attendance, leave, a salary or an advance has moved since. Finalising and reversing need the password typed again; finalising numbers the payslips and writes advance repayments into their accounts; reversing puts them back as owed in new lines and frees the month. Payment is recorded (date, mode, reference) and never made from here.
- **Advances and loans**: requested, approved (the plan may be changed), recorded as given, then taken back through payroll. What is owed is the sum of the append-only account; payroll takes what the plan expects by that month less what has come back, never more than the salary leaves. Paid back by hand, skip a month, waive part, write off (own permission, a reason and the password).
- **Who sees pay**: a run's existence and totals need "See payroll runs"; any person's figures need "See salaries" as well, and are otherwise not sent. A person sees their own payslips only once their payroll is finalised.

Phase 4 (below) posts salary payments and advances into expenses and the day's cash count. The figures should be checked by the accountant on a first real month before they are relied on.

Tests: `WorkforcePayrollRulesTest` (rounding, unpaid days, overtime, daily pay, joining mid-month, statutory rules, advances through payroll and reversal, never below nothing, duplicate prevention, staleness, immutability) and `WorkforcePayrollApiTest` (salary entry and approval, the run through different hands, who sees what, payment, export, reversal, advances, another hospital), plus `pay.test.ts` and `Payroll.test.tsx` on the web side. Made-up staff and figures only.

## Phase 4: expenses with approval, and salaries posted once

The HMS's existing **expenses** table is extended, not replaced (migration `2026_12_20_100001`). Every entry already there is money already paid: its new `status` is `paid` and its `paid_on` is the date it was entered for, so no total changes.

- **Stages**: draft → sent for approval → approved or refused → paid; cancelled at any point with a reason, never erased. Only a paid, not-cancelled expense is money out, counted on the day it was paid (`PaidOut`, the day close, the organisation report).
- **Headings** (`wf_expense_categories`, under Expenses → Headings and repeats): `approval_above` (above this it is approved before it is paid; 0 = always; empty = never, as today) and `approver_limit` (above this approving needs "Approve large expenses"). A typed heading with no set-up rule behaves exactly as before: the cashier enters it as paid.
- **Who**: anyone on the Expenses screen enters, pays approved ones and corrects or cancels their own mistakes of the same day. Approving needs `wf:expenses:approve` (or `:finalise` above the limit); nobody approves their own. Changing the money of, or cancelling, a paid expense from an earlier day needs `wf:expenses:reverse`. The day lock applies throughout.
- **Also**: cost centre (department), bill number, the tax inside the amount, a bill photo or PDF (private disk), monthly repeating expenses that only make a draft when asked, a summary by heading, department, month and mode, and a server-side export that is written to both activity logs.
- **No double counting**: salary payments recorded in Payroll and advances given are posted as paid expenses under the system headings "Salaries (from payroll)" and "Staff advances and loans", one entry per payment, dated the day paid. Those headings refuse hand entries; once payroll posts, the old "Staff salary or advance" heading closes to hand entries too. Posted entries are put right in Payroll or Advances, never in Expenses. A paid payslip cannot be set back to unpaid; a payroll run again after a reversal posts only what had not already been paid to that person for that month (`wf_payslips.paid_amount`).
- **Cash**: advances given in cash and salaries paid in cash come off the drawer; cash paid back on an advance (`wf_advance_ledger.mode`) is added to the cash expected at the day close.

Also fixed in this phase: working a draft payroll out again could remove payslips from the draft, because an Eloquent collection's `except()` matches by the payslip's id rather than the staff member. No payroll had been run on live.

Tests: `WorkforceExpensesTest` (approval and payment, limits, own approval refused, bills, the day lock and earlier-day reversal, payroll and advances posted once, re-run after reversal, cash paid back in the drawer, headings, repeating drafts, export) and a payroll re-work test; `expenseRules.test.ts` and `Expenses.test.tsx` on the web side.

## Duty roster: morning, long-day and night duties

Asked for on 2026-11-20: morning duties (8 AM–6 PM for some, 10 AM–9 PM for others) and night duties (8 PM–8 AM the next day). Shifts were already set up per hospital under Setup → Shifts, and a shift ending at or before it starts already runs into the next morning. What was missing was saying who works which duty on which day.

- `wf_roster` (`RosterDay`) holds a person's duty for a day where it differs from their usual shift and weekly off: a shift, or a day off. No line means the usual. `Roster::duty()` answers for one day; `AttendanceRules::days()` reads it, so the day's figures, lateness, overtime and payroll all follow the roster.
- A check-in takes the rostered shift. One made in the small hours, while last night's night duty has not been checked into yet, counts for that night duty and that day (`Roster::forCheckIn`).
- Before today's duty begins (a night duty in the afternoon) a person is "On duty later", not "Not in yet" and not absent.
- Changing a day's duty measures any attendance already recorded that day again. A month whose payroll is finalised for that person keeps its duties.
- Screens: Attendance → Duty roster (a week per screen, a choice per box, counts of each duty per day at the foot, copy last week, print), "On duty later today" on Today, and "My duties, the next two weeks" in My record. Seen with "See attendance" and set with "Enter attendance", each within its reach, so a department manager rosters only their own departments.

Tests: `WorkforceRosterTest` (the three duties set up, a nurse moved to nights, a check-in after midnight, moving a day off, re-measuring, copying a week, own duties, department reach) and `dutyWords.test.ts`.

## Before a payroll goes further: the readiness check

Added 25 November 2026. A payroll is worked out from attendance, leave and the setup. When those are not ready, pay is taken off people who did nothing wrong, so the run says what it rests on (`App\Workforce\Support\PayrollReadiness`).

Each point is one of two kinds:

- **Must be put right.** The run cannot be reviewed, approved or finalised until it is dealt with.
  - Pay is taken off for absence and no attendance at all was kept for the month.
  - A leave request for the month is still waiting for a decision.
  - An attendance correction for the month is still waiting for a decision.
- **To look at.** The run can go on, but whoever finalises it must tick that they have looked at each, and that is kept in the record of changes.
  - Someone has no attendance and no leave in a month where others were marked.
  - A check-in with no check-out, counted as a worked day.
  - No holidays set up for the year, or no kinds of leave set up, while pay is taken off for absence.
  - Staff left out because no salary stands for them.
  - Anyone paid for less than half the days they were employed in the month.

The check changes no figure. Switching off "Take pay off for absence" under Setup removes the points that depend on it.

## A duty that runs across midnight

Changed 25 November 2026, after the owner found that a night duty checked in at 8 PM was shown after midnight as "not in" with a missing check-out, and could not be dealt with.

- **A duty belongs to the day it began.** A check-in at 8 PM on Tuesday is Tuesday's attendance, whenever the check-out comes.
- **It is still going on after midnight**, whether or not a night shift was set up for the person. A check-in with no check-out counts as someone at work until the latest of: sixteen hours after the check-in (8 PM reaches noon the next day), four hours past the end of its shift where it has one, and the end of its own day. One rule, `AttendanceRules::stillOn()`, is asked by the day list, the day's overview, a person's own record, the check-in card and the check-in itself.
- **On the next day's list** such a person shows as "On duty", with the time and date they came, and a Check out button. They are counted as in.
- **Arriving after midnight.** With no duty set for them and nothing recorded the day before, a check-in before 4 AM belongs to the night that began the evening before.
- **A forgotten check-out no longer blocks the next check-in.** Once a duty is plainly over, its open entry is set aside (state `review`, no check-out, no hours invented) when the person next checks in. It stays on the overview and the day list as "never checked out" until the office puts in the time.

## Regular staff, and doctors and consultants

Added 25 November 2026. The owner: doctors are consultants, not on the payroll; only the working staff are.

- **Regular staff** are in the daily attendance and on the payroll. **Doctors and consultants are not**: nothing marked is not an absence for them, they are not counted in the day's "not in", they are in no payroll, and they are not asked to check in. They may still check in for the record, and their times are under Attendance → "Doctors and consultants".
- The rule (`Employee::scopeRegular()` / `isRegular()`): someone is not regular when their employment is "Consultant" or their login's role is Doctor. A tick on the person's own record, "In the daily attendance and on the payroll", says otherwise for that one person (a doctor on a monthly salary). The column `wf_employees.regular` is empty until that tick is used.

## A person's window

A staff name on the Workforce pages opens a window over the page (`PersonLink`, `PersonCard`, `GET /workforce/employees/{id}/card`): who they are, how to reach them, whether they are in today, their month, their leave, and a doctor's hours. The server sends each part only to someone who may see it elsewhere; pay and bank details are never part of it. On the Staff list a click opens this window first, and "Open full record" goes on to the form.

## Consultants: what they earn and what they are paid

Added 25 November 2026. Doctors and consultants are not in the payroll. The owner pays them in two ways, and both are kept here (`App\Support\ConsultantFees`, `ConsultantController`).

- **Their own cases:** a percentage of what is collected, set for each doctor under Staff → Login and access → Doctor settings. The figures come from `DoctorMoney` (a bill belongs to the doctor of its visit; an inpatient bill to the doctor the patient is admitted under).
- **Visits to another doctor's inpatient:** a fixed fee. The first visit to that patient earns one amount (₹1,000 to start with) and each later day a smaller one (₹500). The ward writes the visit down on the inpatient's page under "Consultants": which doctor and which day, once for a day. It adds nothing to the patient's bill. The doctor the patient is admitted under cannot be entered. Each visit keeps the fee it was given, so a change of rates does not rewrite what was earned.
- **Workforce → Pay → Consultants:** month by month, what each doctor earned, what was paid for that month and what is still due; a doctor's month in full; a statement to print.
- **A payment** is written down against the month it settles. It is also posted into the cash book as a paid expense under "Consultants' fees" (`ExpenseBook::post`), so the day's cash count sees it and it cannot be dated into a closed day. One made by mistake is cancelled with a reason, and its expense with it.
- **Who sees the money:** the permission `canPayConsultants`, which only the admin has unless it is ticked for another login. The ward sees which visit it is, never the fee.


## A doctor on the staff list is a doctor everywhere

Added 25 November 2026. The owner added his consultants under Staff and they did not appear in the doctor box of a new appointment.

- **What "a doctor" is in this system:** a row in `users` with the role Doctor that is switched on. Every list of doctors reads that and nothing else: appointments, the calendar, the doctor's queue, admissions, consultants' visits and payments, services, discounts, the patient phone app. A staff record on its own was never a doctor to any of them.
- **The link:** a tick on the person's staff record, "A doctor who sees patients", with their qualification. For someone who has no login it makes that `users` row with `sign_in_with = 'none'`: it is on every list of doctors and cannot be signed in with (`User::signsIn()`, checked in `AuthController` for password and Google sign-in). `StaffLogin::saving` allows this only for the Doctor role. The server side is `PUT /staff/doctor-for/{employee}` (`StaffController::doctorFor`), open to those who keep staff records as well as those who manage logins, because it gives nobody a way in.
- **Giving them a way in later:** Staff → the person → Login and access: type a password and save. "Stop them signing in, and keep them on the doctors' list" does the reverse and signs out their devices.
- **Off the list:** untick it (a doctor who does not sign in), or mark the person as left. The record is never removed; old appointments, admissions and fees still name them.
- **The patient phone app:** `users.book_online`. A doctor made from the staff list starts hidden from patients' own booking until "Patients can book them from the phone app" is ticked. Doctors who already had logins keep being offered.
- **A consultant is a doctor without being asked** (the owner, 25 November 2026: consultants and doctors must both be in the doctors' list). On the staff form the tick comes on by itself when Employment is "Consultant", or the name is written "Dr …", for a person with no login; it can be unticked by hand. Consultants added before this rule were put on the list once, by the migration `consultants_on_the_doctors_list` (`DoctorList::catchUp()`), which did exactly what the staff check's button does.
- **Finding the ones left out:** the staff check lists "Doctors not on the doctors' list" (a consultant, or someone written as "Dr", with no login) with one button for all of them. Someone written as "Dr" who is not marked as a consultant is only offered there, never put on the list unasked.

## Each doctor's own OPD consultation fee

Added 25 November 2026. The owner: each doctor's consultation has its own price (one ₹600, another ₹500), and choosing the doctor should set it on the bill.

- **One price list, not two.** A doctor's fee is a service that belongs to the doctor (`services.doctor_id`), marked as their usual consultation (`services.is_doctor_fee`). Bills, reports and the rule about who may change a price work as for any other service. `App\Support\DoctorFee` is the one place that reads and sets it.
- **Where it is typed:** Staff → the person → Login and access → Doctor settings → "OPD consultation fee", and the same box beside "A doctor who sees patients" on the staff record. It can also be made by hand under Setup → Services, with the tick "This doctor's usual OPD consultation". Setting or changing it needs the permission to set prices.
- **At the desk:** choosing the doctor in a new appointment puts their consultation and its price on the bill (`usualService()` in `frontend/src/doctorFee.ts`). The desk can still pick another service. The Service box offers the doctor's own services first, then those open to every doctor, and never another doctor's.
- **With no mark,** the first of a doctor's own appointment services is taken, so services already tied to a doctor work without any change.
- **Kept apart on the server:** one doctor's own service is refused for another doctor's visit. A visit moved to another doctor takes that doctor's own service; a bill already made is left as it is.
- **Emptying the box** switches the doctor's service off (old bills still name it); a new amount opens the same one again.
