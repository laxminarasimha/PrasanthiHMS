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
}
