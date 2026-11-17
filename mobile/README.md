# Prasanthi Hospitals mobile app

One app for patients and doctors, for Android and iPhone, written with React Native (Expo).
It talks to the same server as the web system.

## What it does

**Patients** register with name, mobile number and a password, then:
- see a **Today** screen: an appointment coming up, a lab report that is ready, a visit the doctor asked for, medicines due, money owed
- book an appointment for themselves or a **family member**: choose a doctor, a day and a free time; cancel an upcoming one
- keep a monthly medicine list (diabetes, thyroid and the like) that the hospital pharmacy makes up each month; pause, change or stop it
- see prescriptions, lab reports and **bills with money due**, for themselves and their family, once the front desk has verified the account on the web system
- add and remove family members, change their password, call the hospital, open the way in Maps, see the doctors

**Doctors and hospital staff** sign in with their web-system email and password. What they see depends on what they may do on the web system:
- doctors: their appointments for any day, their admitted patients, **lab results outside the normal range** from the last week, and **any patient found by name, mobile or ID**
- doctors also have the **visit pad**: vitals, complaints, history and examination, diagnosis with ICD-10 codes, tests (and sending them to the hospital lab), the prescription with medicine names from the pharmacy list, advice and next visit; their own templates, phrases and medicine groups; saving, finishing, and amendments to a locked visit. It writes to the same record as the web pad.
- a doctor can **start a visit** for any patient they find, and **add to the health summary**
- for a patient: allergies, health summary, recent visits, recent lab results; for an inpatient also the latest bedside observations and **round notes, which can be added from the phone**
- those who see reports (the owner, a manager): **Hospital today**: billed and collected by department, activity counts, whether the day is closed and what is holding it up, and the pharmacy's to-do list. Read-only.

## Run it

```bash
npm install
npx expo start
```

Scan the QR code with the Expo Go app on a phone, or press `w` for a browser preview.

The app talks to the live server by default. To try it against a server on this computer:

```bash
EXPO_PUBLIC_API_URL=http://localhost:8000/api npx expo start --web
```

## Where things are

- `App.tsx`: which screen shows, and remembering the sign-in
- `src/api.ts`: every call to the server, and date helpers
- `src/ui.tsx`, `src/theme.ts`: shared buttons, fields and the hospital's colours
- `src/patient/`: sign in and register, appointments, booking, monthly medicines
- `src/doctor/`: staff sign in, the day's appointments, inpatients, abnormal results, patient search, and the owner's hospital day
- `src/doctor/VisitPad.tsx` and `src/doctor/pad/`: the visit pad, its prescription editor, diagnosis-code search and lab ordering

## Building the apps

Builds are made on Expo's build servers with EAS, from this folder. Only this folder is uploaded
(see `.easignore` at the top of the repository).

**Android, to install directly on phones (an APK file):**

```bash
npx eas-cli build -p android --profile preview
```

When it finishes it prints a link. Open the link on an Android phone to download and install the app.

**Android, for the Play Store (an AAB file):**

```bash
npx eas-cli build -p android --profile production
```

**iPhone:** needs a paid Apple Developer account. The command asks for the Apple ID; type it yourself.

```bash
npx eas-cli build -p ios --profile production
```

After a build, `npx eas-cli submit -p android` or `-p ios` sends it to the store.

The app talks to `https://hms.prasanthihospitals.com/api`. A change that only touches the screens
needs a new build to reach phones; a change on the server reaches every phone at once.

## Before the app stores

- Store page text is in `store/listing.md` and a draft privacy policy in `store/privacy-policy.md`. The policy must be read by the hospital and published at a public web address.
- Google Play needs a Play developer account (one-time fee); Apple needs an Apple Developer account (yearly fee). Both must be in the hospital's name.
- Store reviewers need a test patient login and a test staff login on the live system.
- App icon and opening screen in `assets/` are a plain white cross on the hospital's purple. Replace `icon.png`, `android-icon-foreground.png` and `splash-icon.png` (1024×1024) with the hospital's own logo when it is ready.
- Patient sign-in is mobile number and password for now. OTP comes once an SMS provider is chosen. A patient who forgets the password gets a one-time one from the front desk (patient page, Mobile app account).
