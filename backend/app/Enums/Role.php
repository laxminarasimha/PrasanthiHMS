<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Doctor = 'doctor';
    case Nurse = 'nurse';
    case Pharmacist = 'pharmacist';
    case LabTech = 'lab_tech';
    case Frontdesk = 'frontdesk';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Doctor => 'Doctor',
            self::Nurse => 'Nurse',
            self::Pharmacist => 'Pharmacist',
            self::LabTech => 'Lab Technician',
            self::Frontdesk => 'Front Desk',
        };
    }

    /** Roles allowed to add and edit staff accounts. */
    public function canManageStaff(): bool
    {
        return in_array($this, [self::Admin, self::Doctor], true);
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
        return in_array($this, [self::Admin, self::Doctor], true);
    }

    /** Roles allowed to book appointments and change their status. */
    public function canWorkFrontDesk(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::Nurse, self::Frontdesk], true);
    }

    /** Roles allowed to see the hospital's money and activity reports. */
    public function canViewReports(): bool
    {
        return in_array($this, [self::Admin, self::Doctor], true);
    }

    /** Roles allowed to delete a pharmacy bill, which restocks it and refunds the money. */
    public function canDeleteBills(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::Pharmacist], true);
    }

    /** Roles allowed to charge a different price from the price list while billing. */
    public function canChangePrice(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::Nurse, self::Frontdesk], true);
    }

    /** Roles allowed to admit patients, add IPD charges, take IPD money and discharge. */
    public function canWorkIpd(): bool
    {
        return in_array($this, [self::Admin, self::Doctor, self::Nurse, self::Frontdesk], true);
    }
}
