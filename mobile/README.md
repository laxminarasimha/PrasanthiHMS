# Prasanthi Hospitals mobile app

One app for patients and doctors, for Android and iPhone, written with React Native (Expo).
It talks to the same server as the web system.

## What it does

**Patients** register with name, mobile number and a password, then:
- book an appointment: choose a doctor, a day and a free time; cancel an upcoming one
- keep a monthly medicine list (diabetes, thyroid and the like) that the hospital pharmacy makes up each month; pause, change or stop it

- see their own prescriptions and lab reports, once the front desk has verified the account on the web system
- change their password and call the hospital

**Doctors** sign in with their web-system email and password and see:
- their appointments for any day
- their admitted patients
- for any of those patients: allergies, health summary, recent visits, recent lab results, and for inpatients the latest bedside observations

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
- `src/doctor/`: sign in and the day's appointments

## Before the app stores

- App icon and splash image in `assets/` are still the Expo defaults.
- Store builds are made with EAS (`npx eas build`), which needs an Expo account, a Google Play
  developer account and an Apple developer account.
- Patient sign-in is mobile number and password for now. OTP comes once an SMS provider is chosen. A patient who forgets the password gets a one-time one from the front desk (patient page, Mobile app account).
