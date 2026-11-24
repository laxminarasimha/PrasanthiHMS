<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Doctor = 'doctor';
    // Runs the hospital for the owner: sees everything and manages the day's work, but does not write clinical notes,
    // approve results, change prices or reverse bills unless that is given to the person. Not a doctor to book with.
    case Manager = 'manager';
    // Three desk jobs of the workforce module. What they may do there is set in App\Workforce\Support\Permissions;
    // in the rest of the system they start with nothing but what is listed below.
    case Hr = 'hr';
    case Accountant = 'accountant';
    case DeptManager = 'dept_manager';
    case Nurse = 'nurse';
    case Pharmacist = 'pharmacist';
    case LabTech = 'lab_tech';
    case Frontdesk = 'frontdesk';
    // Someone who works in the hospital but not in this system: wards, housekeeping, security, drivers. Their login
    // opens their own attendance, leave and payslips and nothing else, so it may be opened with a PIN.
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Doctor => 'Doctor',
            self::Manager => 'Hospital Manager',
            self::Hr => 'HR and Payroll',
            self::Accountant => 'Accountant',
            self::DeptManager => 'Department Manager',
            self::Nurse => 'Nurse',
            self::Pharmacist => 'Pharmacist',
            self::LabTech => 'Lab Technician',
            self::Frontdesk => 'Front Desk',
            self::Staff => 'Staff (own record only)',
        };
    }

    /** Closing a day's accounts is for the admin, and for anyone the admin names under Setup → Staff. */
    public function canCloseDay(): bool
    {
        return $this === self::Admin;
    }

    /** Roles allowed to add and edit staff accounts. */
    public function canManageStaff(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::Manager], true);
    }

    /** Roles allowed to write clinical notes and prescriptions. Nurses are included so they can record vitals. */
    public function canConsult(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::Nurse], true);
    }

    /** Roles allowed to enter lab results and edit the test catalogue. */
    public function canRunLab(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::LabTech], true);
    }

    /** Roles allowed to receive stock, sell medicines and edit the medicine list. */
    public function canRunPharmacy(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::Pharmacist], true);
    }

    /** Roles allowed to change price lists and hospital setup: OPD services, beds and IPD services. */
    public function canManageSetup(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::Manager], true);
    }

    /** Roles allowed to book appointments and change their status. */
    public function canWorkFrontDesk(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::Nurse, self::Frontdesk, self::Manager], true);
    }

    /** Roles allowed to scan a patient's old papers, check what was read from them and file them in the record. */
    public function canFilePastRecords(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::Nurse, self::Frontdesk, self::Manager], true);
    }

    /** Roles allowed to approve a discharge summary or an IPD bill, which locks it. */
    public function canApproveIpd(): bool
    {
        return in_array($this, [self::Admin, self::Doctor], true);
    }

    /** Roles allowed to see the hospital's money and activity reports. */
    public function canViewReports(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::Manager, self::Accountant], true);
    }

    /** Roles allowed to delete a pharmacy bill, which restocks it and refunds the money. */
    public function canDeleteBills(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::Pharmacist], true);
    }

    /**
     * Roles allowed to approve stock counts and corrections in the pharmacy, and to see what is missing.
     * Pharmacists count the shelf but do not approve their own counts, so stock cannot be written off by those who handle it.
     */
    public function canAuditPharmacy(): bool
    {
        return in_array($this, [self::Admin, self::Doctor], true);
    }

    /** Roles allowed to charge a different price from the price list while billing. */
    public function canChangePrice(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::Nurse, self::Frontdesk], true);
    }

    /** Roles allowed to write down money paid out of the hospital's cash and accounts: the day-to-day expenses. */
    public function canRecordExpenses(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::Frontdesk, self::Manager, self::Accountant], true);
    }

    /** Roles allowed to admit patients, add IPD charges, take IPD money and discharge. */
    public function canWorkIpd(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::Nurse, self::Frontdesk, self::Manager], true);
    }

    /**
     * Roles allowed to read clinical records (visits, case history, bedside charts, the health summary) without
     * writing anything. Those who treat or test already read them through their own work.
     */
    public function canViewClinical(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::Manager, self::Nurse, self::LabTech], true);
    }

    /** Roles allowed to open the pharmacy's screens and reports to look, without changing anything. */
    public function canViewPharmacy(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::Manager, self::Pharmacist], true);
    }

    /** Roles allowed to approve a reported lab result, or withdraw that approval. Entering results is canRunLab. */
    public function canApproveLab(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::LabTech], true);
    }

    /** Roles allowed to set the price of a service, an IPD service or a bed. Without it the rest of setup can still be changed. */
    public function canSetPrices(): bool
    {
        return in_array($this, [self::Admin, self::Doctor], true);
    }

    /** Roles allowed to download lists and reports as a file. Everyone who could before; not a manager unless given it. */
    public function canExportData(): bool
    {
        return ! in_array($this, [self::Manager, self::Hr, self::DeptManager], true);
    }
}
