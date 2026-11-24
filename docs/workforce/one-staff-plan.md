# One Staff list and simple sign-in: the plan

**Status: a plan only. Nothing here is built yet.** Written 24 November 2026 for the owner to read and approve before any work starts.

## 1. What was asked for

- One place for staff, not a staff screen under Setup and another under Workforce.
- A simpler way for every member of staff to sign in from their own phone.
- Check-in and check-out from that phone.
- Planned as a whole first, so it does not become a problem later.

## 2. How it is today

| | Setup → Staff | Workforce → Staff |
|---|---|---|
| What it holds | A login: email, password, role, permissions | A person: employee number, designation, shift, joining date, bank, salary |
| Who is in it | Only people who sign in | Everyone, with or without a login |
| Asked in both | Name, phone, gender, department | Name, phone, gender, department |
| Also holds | Doctor's signature, OPD hours, share of collections | Emergency contact, reports to, leave, attendance, pay |

What goes wrong because of this:

1. The same person is typed twice, and the two are joined by hand afterwards.
2. Nothing keeps the two copies of a name or phone number the same.
3. Signing in needs an email address. Many staff have none.
4. There is no role for someone who only needs their own attendance, leave and payslip.
5. Marking someone as left does not close their login.
6. Two department lists: free text on the login, the hospital's own list on the staff record.
7. A login can belong to one person only. A login shared by several people breaks attendance and the record of who did what.

## 3. How it will be

### One Staff list

There is one list, called **Staff**. Each person has one page with these sections:

| Section | What is in it | Who sees it |
|---|---|---|
| Details | Name, mobile, gender, department, designation, shift, joining date, reports to, emergency contact | Those who keep staff records |
| Login and access | Whether the person signs in, how, their role, their permissions, and the history of changes to these | Those who manage logins |
| Doctor settings | Signature, seal, OPD hours, slot length, share of collections. Shown only for doctors | Those who manage logins; the share only to an admin, as today |
| Pay and bank | Bank, tax and identity numbers, salary | HR and admin, as today |
| Attendance and leave | Links to the person's attendance and leave | As today |

Setup → Staff leaves the menu. Its old address opens the new page, so bookmarks still work.

### Adding a person

One form. Name and mobile number are asked once. A tick, "This person signs in", opens the role and access boxes on the same form. There is no separate joining step.

### Signing in

| Who | Types | Notes |
|---|---|---|
| Staff who only need their own record (wards, housekeeping, security, drivers) | Mobile number and a 6-digit PIN | A new role, "Staff (own record only)" |
| Staff who work in the system (front desk, lab, pharmacy, nurses, HR, accounts) | Mobile number or email, and a password | Password rules as today |
| Doctors, managers, admin | Mobile number or email, and a password | As today, with mobile number as a second way in |
| Anyone, later | Google, or a code sent by SMS | Both wait on outside accounts |

- The phone stays signed in while it is used. A phone not used for 30 days is signed out. This rule exists already.
- A starting PIN is made at random and shown once to whoever gives the login. The person must choose their own PIN the first time they sign in.
- A forgotten PIN is reset from the person's Staff page. That signs out all their devices.
- A PIN login closes for 15 minutes after 5 wrong tries.

### Check-in from the phone

- Staff with "own record only" open the app straight onto the check-in card. Their bottom bar is Home, Attendance, Profile.
- Everyone else keeps the card at the top of their own home screen, as built already.
- The switch "Let staff mark their own attendance" stays under Workforce → Setup and stays the owner's to turn on.
- Optional safeguard: check-in is accepted only on the hospital's own Wi-Fi. No GPS and no fingerprint, in keeping with the standing rule.

### Leaving and coming back

- Marking a person as left closes their login at the same moment and signs out their devices.
- A login can also be switched off on its own, while the person stays on the staff.
- Someone who rejoins gets their old record back, with its history. Nobody is deleted.

## 4. Rules that keep this from becoming a problem

1. **One person, one staff record, one login.** A login is never shared.
2. **Every login belongs to a staff record.** The only exception is a login marked "shared screen", for a waiting-room display. It has no attendance and no pay.
3. **Typed once.** Name, mobile, gender and department live on the staff record. The login copies them and cannot differ.
4. **A mobile number opens one login only.** Two people cannot sign in with the same number.
5. **A PIN opens only one's own record.** Any role that can see patients, money or other staff needs a full password. Changing someone from "own record only" to a wider role asks for a password at that moment.
6. **Access changes keep their reason.** Every change of role or permission is recorded with who made it and why, as today.
7. **Nobody gives more than they hold.** The existing rule stays: only an admin gives sensitive permissions.
8. **Pay stays private.** Nothing about pay or bank is shown to anyone who cannot see it today.
9. **History is never rewritten.** Bills, visits and the record of changes keep pointing at the same logins.

## 5. Getting from today to there

Each stage is built, tested with made-up staff, and shipped on its own. A stage that touches live data shows a preview first and writes only what is approved.

| Stage | What it does | Touches live data? | If something is wrong |
|---|---|---|---|
| 0. Staff check | A look-only screen for the admin: logins with no staff record, staff with no login, likely matches, details that differ, any mobile number used twice | No | Nothing to undo |
| 1. Foundations | Sign in with mobile number or email. The new "own record only" role. PIN logins. Person and login kept in step. Leaving closes the login | Structure only; no row is changed | Old sign-in keeps working throughout |
| 2. Join what exists | From the check screen: join the matches the owner approves, create a staff record for each remaining login, mark any shared-screen login | Yes, only what is approved | Each join can be undone from the person's page |
| 3. One Staff screen | The single Staff page with its sections, the one "add a person" form, and Setup → Staff retired | No | The old screen can be put back in one release |
| 4. Logins for everyone | "Give login" on a person, and one button for everyone without one. Starting PINs handed out. The owner switches on self check-in | Yes: new logins, made by the hospital's own staff | A login can be switched off at once |
| 5. Tidy up | One department list. The phone app (TestFlight) takes mobile number too. Only then the old WordPress staff are brought in, so each lands once | Yes, with a preview | Per step |

Stage 0 comes first because the live data cannot be read from here. That screen shows the real state before anything is decided.

## 6. What could go wrong, and the answer to each

| Risk | Answer |
|---|---|
| Two staff share one mobile number | Stage 0 lists them. One of them signs in with another number or an email |
| A login is shared by several people today | Stage 0 asks. Each person gets their own login; the shared one is switched off after a handover period |
| A PIN is guessed | 6 digits, 5 tries, 15-minute lock, and a PIN opens only that person's own record |
| Someone checks in from home | The optional hospital Wi-Fi rule |
| Someone checks in for a colleague | Each person has their own PIN and may not share it. Corrections need approval, as today |
| A starting PIN is seen by others | Shown once, never stored in readable form, and must be changed at first sign-in |
| HR could give themselves wider access | HR may give only "own record only" logins. Wider roles stay with those who manage logins today |
| Old phone app build stops working | The server keeps accepting email sign-in exactly as now |
| A wrong match in stage 2 | Only exact matches are proposed; the rest are chosen by hand; any join can be undone |

## 7. What stays as it is

- Passwords, roles and permissions of existing logins.
- The patient app and patient accounts. They are separate and are not touched.
- Payroll, salaries, advances, leave balances and attendance already recorded.
- The record of changes, in both the hospital system and the workforce module.

## 8. Decisions needed from the owner

| # | Decision | Suggested |
|---|---|---|
| 1 | Where the single Staff list sits | Under Workforce, named "Staff" |
| 2 | PIN length for own-record staff | 6 digits |
| 3 | Is any login used by more than one person today? | To be answered; one login per person from now on |
| 4 | Who may give and reset own-record logins | Admin, manager and HR. Wider roles: only those who manage logins today |
| 5 | Check-in only on the hospital's Wi-Fi | Yes, from stage 4. It can also be added later |
| 6 | Should doctors also check in from their phone? | Only if the hospital keeps doctors' attendance |
| 7 | Visiting consultants who are not on the payroll | One staff record each, marked "consultant", with no pay |

## 9. Notes for building

For whoever builds this; the owner can skip it.

- **Tables stay two.** `users` is the login, `wf_employees` the person. `wf_employees` already has a unique `(branch_id, user_id)`. Merging the tables would mean rewriting every reference to `users.id` for no visible gain.
- **`users` changes (structure only, guarded):** `email` nullable; a normalised mobile column unique among logins; `sign_in_with` (`password` or `pin`); `must_change_secret`; `is_shared_screen`. New `Role::Staff` with only `wf:self:view` and `wf:self:create`.
- **Sign-in:** `AuthController::login` takes `login` (mobile or email) and still accepts `email` for old phone app builds. `SignInGuard` is keyed by the login typed; 5 tries for a PIN login, 10 as now for a password.
- **Kept in step on the server, not in the screen:** saving an `Employee` writes name, phone and gender through to its `User`; status `inactive` sets `is_active = false` and deletes tokens. One place: a small `App\Workforce\Support\StaffLogin` that every writer goes through.
- **Access rules are reused, not copied:** the new Login and access section calls the existing `StaffController` rules (`onlyAdminsTouchAdmins`, `onlyAdminsGiveSensitive`, permission history).
- **Watch:** `StaffController::index` is not narrowed to a branch while every workforce list is. The single page uses the workforce reach.
- **Screens:** `frontend/src/workforce/StaffDirectory.tsx` gains the sections; the permission, availability, signature and share parts move out of `pages/Staff.tsx` into shared components; `/staff` redirects. `homeFor()` sends an own-record login to `/workforce/me`.
- **Phone app:** `mobile/src/doctor/Login.tsx` takes mobile number or email. A new TestFlight build is asked for before upload.
- **Tests:** each rule in section 4 gets a server test; `nav.test.ts` covers the own-record bar.
