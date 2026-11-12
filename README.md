# PrasanthiHMS

Hospital Management System for Prasanthi Hospitals: patients, doctors, front desk, lab, inpatient management and pharmacy.

## Stack

- `frontend/` — React 19 + TypeScript, built with Vite
- `backend/` — Laravel 13 (PHP 8.5) JSON API, Sanctum for auth
- MySQL database

## Local setup

Requirements: Node 24+, PHP 8.5+, Composer, MySQL.

```bash
# Database
mysql -u root -e "CREATE DATABASE IF NOT EXISTS prasanthi_hms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# Backend (http://localhost:8000)
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed   # one branch, an admin login and sample patients
php artisan serve

# Frontend (http://localhost:5173), in a second terminal
cd frontend
npm install
npm run dev
```

The frontend proxies `/api/*` to the Laravel server, so open http://localhost:5173.

Sign in with the seeded admin account `admin@prasanthi.test` (a sample doctor, `doctor@prasanthi.test`, is seeded too), with the password set by `SEED_ADMIN_PASSWORD` in `backend/.env` (see `.env.example` for the default). This account is for local development only.

## What works so far

- Sign in and sign out for staff, with roles: Admin, Doctor, Nurse, Pharmacist, Lab Technician, Front Desk
- Staff management (Admin and Doctor only): add, edit, deactivate
- Patient registration, search by name, mobile or patient ID, and editing
- Front Desk: the day's appointment queue with status tracking, booking, billing, payments, refunds and a printable receipt
- Services: the price list used for appointments and bills
- Doctor: the day's patient queue, a visit pad (vitals, notes, medicines, tests, advice, follow-up), past visits and a printable prescription

Run the backend tests with `php artisan test` from `backend/`.

## Workflow

Each module is built on its own branch (`feature/<module>`), pushed when testable, and merged through a pull request.
