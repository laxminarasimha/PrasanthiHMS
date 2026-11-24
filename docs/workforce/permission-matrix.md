# Workforce module: permission matrix

Written by `php artisan workforce:permission-matrix` from `backend/app/Workforce/Support/Permissions.php`. Do not edit by hand.

- A permission is named `wf:<what>:<action>`; the action is one of view, create, edit, approve, finalise, reverse, export, admin.
- Each cell says how far the permission reaches for that role to start with: **All staff** of the branch, the staff of the person's **own departments**, their **own record**, or — (not given).
- A tick or untick made for one person under Staff → Login and access overrides this for that person. An untick always wins. A tick where the role had nothing reaches all staff.
- The admin login always has everything, over all staff.
- **Admin only** marks a permission that only an admin login may add on top of a role.
- Other staff (doctors, nurses, pharmacists, lab technicians, front desk) start with their own records only.

## Own records

| Permission | What it allows | Owner / Admin | Hospital Manager | HR and Payroll | Accountant | Department Manager | Other staff (self-service) | Admin only |
|---|---|---|---|---|---|---|---|---|
| `wf:self:view` | See own attendance, leave and payslips | All staff | Own record | Own record | Own record | Own record | Own record |  |
| `wf:self:create` | Ask for leave and corrections | All staff | Own record | Own record | Own record | Own record | Own record |  |

## Staff directory

| Permission | What it allows | Owner / Admin | Hospital Manager | HR and Payroll | Accountant | Department Manager | Other staff (self-service) | Admin only |
|---|---|---|---|---|---|---|---|---|
| `wf:staff:view` | See the staff directory | All staff | All staff | All staff | All staff | Own departments | — |  |
| `wf:staff:create` | Add staff | All staff | All staff | All staff | — | — | — |  |
| `wf:staff:edit` | Change staff details | All staff | All staff | All staff | — | — | — |  |
| `wf:staff:export` | Export the staff directory | All staff | — | All staff | — | — | — | Yes |
| `wf:staff_private:view` | See bank, tax and identity details | All staff | — | All staff | — | — | — | Yes |
| `wf:staff_private:edit` | Change bank, tax and identity details | All staff | — | All staff | — | — | — | Yes |

## Attendance

| Permission | What it allows | Owner / Admin | Hospital Manager | HR and Payroll | Accountant | Department Manager | Other staff (self-service) | Admin only |
|---|---|---|---|---|---|---|---|---|
| `wf:attendance:view` | See attendance | All staff | All staff | All staff | All staff | Own departments | — |  |
| `wf:attendance:create` | Enter attendance | All staff | All staff | All staff | — | Own departments | — |  |
| `wf:attendance:edit` | Correct attendance directly | All staff | All staff | All staff | — | — | — |  |
| `wf:attendance:approve` | Decide correction requests | All staff | All staff | All staff | — | Own departments | — |  |
| `wf:attendance:export` | Export attendance | All staff | — | All staff | — | — | — | Yes |

## Leave

| Permission | What it allows | Owner / Admin | Hospital Manager | HR and Payroll | Accountant | Department Manager | Other staff (self-service) | Admin only |
|---|---|---|---|---|---|---|---|---|
| `wf:leave:view` | See leave and balances | All staff | All staff | All staff | All staff | Own departments | — |  |
| `wf:leave:create` | Enter leave for staff | All staff | All staff | All staff | — | Own departments | — |  |
| `wf:leave:approve` | Approve or refuse leave | All staff | All staff | All staff | — | Own departments | — |  |
| `wf:leave:edit` | Adjust leave balances | All staff | — | All staff | — | — | — | Yes |
| `wf:leave:export` | Export leave | All staff | — | All staff | — | — | — | Yes |

## Pay

| Permission | What it allows | Owner / Admin | Hospital Manager | HR and Payroll | Accountant | Department Manager | Other staff (self-service) | Admin only |
|---|---|---|---|---|---|---|---|---|
| `wf:salary:view` | See salaries | All staff | — | All staff | All staff | — | — | Yes |
| `wf:salary:edit` | Set salaries | All staff | — | All staff | — | — | — | Yes |
| `wf:salary:approve` | Approve a salary change | All staff | — | — | All staff | — | — | Yes |

## Payroll

| Permission | What it allows | Owner / Admin | Hospital Manager | HR and Payroll | Accountant | Department Manager | Other staff (self-service) | Admin only |
|---|---|---|---|---|---|---|---|---|
| `wf:payroll:view` | See payroll runs | All staff | All staff | All staff | All staff | — | — |  |
| `wf:payroll:create` | Start a payroll run | All staff | — | All staff | — | — | — | Yes |
| `wf:payroll:edit` | Review a payroll run | All staff | — | All staff | — | — | — | Yes |
| `wf:payroll:approve` | Approve a payroll run | All staff | — | — | All staff | — | — | Yes |
| `wf:payroll:finalise` | Finalise a payroll run | All staff | — | — | — | — | — | Yes |
| `wf:payroll:reverse` | Reverse a finalised payroll | All staff | — | — | — | — | — | Yes |
| `wf:payroll:export` | Export payroll and payslips | All staff | — | All staff | All staff | — | — | Yes |

## Advances and loans

| Permission | What it allows | Owner / Admin | Hospital Manager | HR and Payroll | Accountant | Department Manager | Other staff (self-service) | Admin only |
|---|---|---|---|---|---|---|---|---|
| `wf:advances:view` | See advances and loans | All staff | All staff | All staff | All staff | — | — |  |
| `wf:advances:create` | Enter an advance or loan request | All staff | All staff | All staff | — | — | — |  |
| `wf:advances:approve` | Approve and issue advances | All staff | — | — | All staff | — | — | Yes |
| `wf:advances:reverse` | Waive or write off an advance | All staff | — | — | — | — | — | Yes |
| `wf:advances:export` | Export advances | All staff | — | — | All staff | — | — | Yes |

## Expenses

| Permission | What it allows | Owner / Admin | Hospital Manager | HR and Payroll | Accountant | Department Manager | Other staff (self-service) | Admin only |
|---|---|---|---|---|---|---|---|---|
| `wf:expenses:view` | See expenses | All staff | All staff | — | All staff | Own record | — |  |
| `wf:expenses:create` | Enter an expense | All staff | All staff | — | All staff | Own record | — |  |
| `wf:expenses:edit` | Change an expense | All staff | All staff | — | All staff | — | — |  |
| `wf:expenses:approve` | Approve or refuse expenses | All staff | All staff | — | All staff | — | — |  |
| `wf:expenses:finalise` | Approve large expenses and record payment | All staff | — | — | All staff | — | — | Yes |
| `wf:expenses:reverse` | Reverse or cancel a paid expense | All staff | — | — | — | — | — | Yes |
| `wf:expenses:export` | Export expenses | All staff | — | — | All staff | — | — | Yes |

## Workforce reports

| Permission | What it allows | Owner / Admin | Hospital Manager | HR and Payroll | Accountant | Department Manager | Other staff (self-service) | Admin only |
|---|---|---|---|---|---|---|---|---|
| `wf:reports:view` | See workforce dashboards and reports | All staff | All staff | All staff | All staff | Own departments | — |  |
| `wf:reports:export` | Export workforce reports | All staff | — | All staff | All staff | — | — | Yes |

## Workforce setup

| Permission | What it allows | Owner / Admin | Hospital Manager | HR and Payroll | Accountant | Department Manager | Other staff (self-service) | Admin only |
|---|---|---|---|---|---|---|---|---|
| `wf:settings:admin` | Set up shifts, holidays, leave types and categories | All staff | All staff | All staff | — | — | — |  |
| `wf:audit:view` | See the workforce record of changes | All staff | — | — | — | — | — | Yes |
| `wf:audit:export` | Export the record of changes | All staff | — | — | — | — | — | Yes |
