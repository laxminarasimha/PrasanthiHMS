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
php artisan serve

# Frontend (http://localhost:5173), in a second terminal
cd frontend
npm install
npm run dev
```

The frontend proxies `/api/*` to the Laravel server, so open http://localhost:5173.

## Workflow

Each module is built on its own branch (`feature/<module>`), pushed when testable, and merged through a pull request.
