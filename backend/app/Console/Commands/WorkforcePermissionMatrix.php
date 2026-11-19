<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Workforce\Support\Permissions;
use Illuminate\Console\Command;

/**
 * Writes docs/workforce/permission-matrix.md from the permission catalogue and role presets in code, so the
 * document a person reviews is always what the system actually does.
 */
class WorkforcePermissionMatrix extends Command
{
    protected $signature = 'workforce:permission-matrix {--print : Show it instead of writing the file}';

    protected $description = 'Write the workforce permission matrix document from the code';

    public function handle(): int
    {
        $roles = [
            'Owner / Admin' => Role::Admin, 'Hospital Manager' => Role::Manager, 'HR and Payroll' => Role::Hr,
            'Accountant' => Role::Accountant, 'Department Manager' => Role::DeptManager, 'Other staff (self-service)' => Role::Frontdesk,
        ];
        $reach = [Permissions::ALL => 'All staff', Permissions::DEPARTMENT => 'Own departments', Permissions::SELF => 'Own record'];

        $out = "# Workforce module: permission matrix\n\n"
            ."Written by `php artisan workforce:permission-matrix` from `backend/app/Workforce/Support/Permissions.php`. Do not edit by hand.\n\n"
            ."- A permission is named `wf:<what>:<action>`; the action is one of view, create, edit, approve, finalise, reverse, export, admin.\n"
            ."- Each cell says how far the permission reaches for that role to start with: **All staff** of the branch, the staff of the person's **own departments**, their **own record**, or — (not given).\n"
            ."- A tick or untick made for one person under Setup → Staff overrides this for that person. An untick always wins. A tick where the role had nothing reaches all staff.\n"
            ."- The admin login always has everything, over all staff.\n"
            ."- **Admin only** marks a permission that only an admin login may add on top of a role.\n"
            ."- Other staff (doctors, nurses, pharmacists, lab technicians, front desk) start with their own records only.\n";

        $group = null;
        foreach (Permissions::CATALOGUE as $key => [$heading, $label, , $sensitive]) {
            if ($heading !== $group) {
                $group = $heading;
                $out .= "\n## {$heading}\n\n| Permission | What it allows | ".implode(' | ', array_keys($roles))." | Admin only |\n|---|---|".str_repeat('---|', count($roles))."---|\n";
            }
            $cells = array_map(fn (Role $role) => $role === Role::Admin ? 'All staff' : ($reach[Permissions::preset($role)[$key] ?? ''] ?? '—'), $roles);
            $out .= "| `{$key}` | {$label} | ".implode(' | ', $cells).' | '.($sensitive ? 'Yes' : '')." |\n";
        }

        if ($this->option('print')) {
            $this->line($out);

            return self::SUCCESS;
        }
        file_put_contents(base_path('../docs/workforce/permission-matrix.md'), $out);
        $this->info('Written to docs/workforce/permission-matrix.md');

        return self::SUCCESS;
    }
}
