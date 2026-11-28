<?php

namespace App\Models;

use App\Enums\Role;
use App\Support\StaffLogin;
use App\Workforce\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'branch_id', 'role', 'gender', 'phone', 'department', 'signature_text', 'is_active', 'pad_hidden_sections', 'hidden_tabs', 'appearance', 'pad_section_order', 'rx_settings', 'permissions', 'signature_path', 'seal_path', 'availability', 'slot_minutes', 'share_front_desk', 'share_lab', 'share_ipd', 'sign_in_with', 'must_change_secret', 'is_shared_screen', 'book_online'])]
#[Hidden(['password', 'remember_token', 'signature_path', 'seal_path', 'permissions', 'share_front_desk', 'share_lab', 'share_ipd'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'pad_hidden_sections' => 'array',
            'hidden_tabs' => 'array',
            'appearance' => 'array',
            'pad_section_order' => 'array',
            'rx_settings' => 'array',
            'permissions' => 'array',
            'availability' => 'array',
            'slot_minutes' => 'integer',
            // A doctor's share of collections is pay: it is sent only to an admin on the staff screen.
            'share_front_desk' => 'float', 'share_lab' => 'float', 'share_ipd' => 'float',
            'must_change_secret' => 'boolean', 'is_shared_screen' => 'boolean', 'book_online' => 'boolean',
        ];
    }

    /** What a doctor's prescription settings are until they change them. */
    public const RX_DEFAULTS = [
        'frequencies' => ['1-0-0', '0-1-0', '0-0-1', '1-0-1', '1-1-0', '0-1-1', '1-1-1', '1-1-1-1', 'SOS', 'Stat', 'Alternate days', 'Once a week'],
        'timings' => ['After food', 'Before food', 'Empty stomach', 'At bedtime'],
        // en: as typed; both: the typed words with the translation beside them; local: the translation alone.
        'print_language' => 'en',
        'translations' => [],
        'capitalize_name' => false,
        // How the prescription is laid out on paper.
        'print' => [
            'letterhead' => false, // true: leave the heading off and keep the top of the page clear for pre-printed paper
            'margin_top_mm' => 12, 'margin_bottom_mm' => 12, 'margin_side_mm' => 12,
            'font_size_pt' => 11,
            'show_mobile' => true, 'show_patient_id' => true,
            'sections' => ['vitals' => true, 'complaints' => true, 'history' => true, 'past_medication' => true, 'examination' => true, 'results' => true, 'diagnosis' => true, 'plan' => true, 'tests' => true, 'advice' => true, 'refer' => true, 'next_visit' => true],
            'columns' => ['dose' => true, 'frequency' => true, 'timing' => true, 'duration' => true, 'instructions' => true],
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function rxSettings(): array
    {
        $settings = array_merge(self::RX_DEFAULTS, $this->rx_settings ?? []);
        // Fill in any print choice saved before it existed, down to each section and column.
        $settings['print'] = array_replace_recursive(self::RX_DEFAULTS['print'], $settings['print'] ?? []);

        return $settings;
    }

    /** What a person may be allowed to do, with the wording shown on the staff screen. */
    public const ABILITIES = [
        'canWorkFrontDesk' => ['Front desk: appointments and billing', 'Book appointments, change their status, take vitals and make bills.'],
        'canChangePrice' => ['Change the price while billing', 'Charge a price different from the price list for a service.'],
        'canEnterEarlierDays' => ['Bill for an earlier day', 'Enter a bill, or money that was taken, for a day already gone, so that it counts on that day in the lists and reports. Without this, everything a person enters is today’s.'],
        'canRecordExpenses' => ['Expenses', 'Write down money paid out (wages advanced, tea, repairs and the like), and correct or cancel an entry.'],
        'canFilePastRecords' => ['Old paper records', 'Scan the papers a patient brings from before, check what was read from them and file them in the record.'],
        'canConsult' => ['Doctor: visit pad', 'Open the visit pad, write notes and prescriptions, and use the doctor library.'],
        'canViewClinical' => ['See clinical records', 'Read visits, the case history, bedside charts and the health summary, without writing anything.'],
        'canRunLab' => ['Lab', 'Enter and approve lab results, and edit lab tests, packages and preferences.'],
        'canApproveLab' => ['Approve lab results', 'Approve a reported lab result, or withdraw the approval.'],
        'canRunPharmacy' => ['Pharmacy', 'Sell medicines, receive stock, and edit medicines, bills and invoices.'],
        'canViewPharmacy' => ['Pharmacy: look only', 'Open the pharmacy screens and reports to see stock, bills and purchases, without changing anything.'],
        'canDeleteBills' => ['Delete and cancel bills', 'Delete a pharmacy bill, which restocks it, or cancel a front desk or lab bill made by mistake. The money is refunded.'],
        'canAuditPharmacy' => ['Pharmacy stock audit', 'Approve stock counts and stock corrections, and see where stock went and what is missing.'],
        'canWorkIpd' => ['IPD', 'Admit patients, add IPD charges, take IPD payments and discharge.'],
        'canApproveIpd' => ['Approve IPD summaries and bills', 'Approve a discharge summary or an IPD bill, which locks it against changes.'],
        'canViewReports' => ['Reports', "See the hospital's billing, collection and activity reports."],
        'canExportData' => ['Export to a file', 'Download lists and reports as a spreadsheet file.'],
        'canCloseDay' => ['Close the day', 'Count the cash and close a day’s accounts, which locks that day’s bills and payments.'],
        'canManageSetup' => ['Price lists and setup', 'Change services, OPD hours, beds and IPD services.'],
        'canSetPrices' => ['Set prices', 'Set or change the price of a service, an IPD service or a bed.'],
        'canPayConsultants' => ['Consultants’ fees and payments', 'See what each consultant has earned, set their share of collections and the visit fees, and record what is paid to them.'],
        'canManageStaff' => ['Staff and permissions', 'Add staff, reset passwords and change what each person may do.'],
    ];

    /**
     * Whether this person may do something: their own setting if one was made, otherwise what their role allows.
     * Admins always keep everything, so the hospital cannot lock itself out.
     */
    public function allows(string $ability): bool
    {
        // The workforce module's permissions have their own presets and reach.
        if (Permissions::has($ability)) {
            return Permissions::allows($this, $ability);
        }
        $default = method_exists($this->role, $ability) && $this->role->{$ability}();

        return $this->role === Role::Admin ? $default : (bool) ($this->permissions[$ability] ?? $default);
    }

    /**
     * Permissions that hand over clinical authority, money or control of the system. Only an admin may give one of
     * these to a person, or give a role that carries one; anyone who manages staff may still take them away.
     */
    public const SENSITIVE = ['canConsult', 'canApproveLab', 'canApproveIpd', 'canChangePrice', 'canEnterEarlierDays', 'canSetPrices', 'canDeleteBills', 'canAuditPharmacy', 'canCloseDay', 'canExportData', 'canManageStaff', 'canPayConsultants'];

    /**
     * Whether this person may do this in the pharmacy: anything if they run it, or only what reads without
     * changing anything if they are allowed to look.
     */
    public function mayInPharmacy(Request $request): bool
    {
        return $this->allows('canRunPharmacy') || ($request->isMethodSafe() && $this->allows('canViewPharmacy'));
    }

    /** Whether this person may read clinical records: through their own clinical or lab work, or by being allowed to look. */
    public function seesClinical(): bool
    {
        return $this->allows('canConsult') || $this->allows('canRunLab') || $this->allows('canViewClinical');
    }

    /**
     * @return array<string, bool>
     */
    public function getAbilitiesAttribute(): array
    {
        return collect(self::catalogue())->map(fn ($text, $ability) => $this->allows($ability))->all();
    }

    /**
     * Every permission there is, the system's own and the workforce module's: [name, what it allows, group, sensitive].
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function catalogue(): array
    {
        $own = collect(self::ABILITIES)->map(fn (array $text, string $key) => [$text[0], $text[1], 'Hospital system', in_array($key, self::SENSITIVE, true)]);
        $module = collect(Permissions::CATALOGUE)->map(fn (array $text) => [$text[1], $text[2], 'Workforce · '.$text[0], $text[3]]);

        return $own->all() + $module->all();
    }

    /** Whether a role gives a permission to start with, before any change made for one person. */
    public static function roleAllows(Role $role, string $ability): bool
    {
        return Permissions::has($ability) ? isset(Permissions::preset($role)[$ability]) : (method_exists($role, $ability) && $role->{$ability}());
    }

    /** A login keeps its mobile number and its limits whoever saves it, and however. */
    protected static function booted(): void
    {
        static::saving(fn (User $user) => StaffLogin::saving($user));
    }

    /**
     * Whether this person can sign in at all. A doctor can be on the hospital's lists (for appointments, admissions,
     * consultant visits and their fees) without ever signing in: a visiting consultant, say.
     */
    public function signsIn(): bool
    {
        return $this->sign_in_with !== 'none';
    }

    /** Whether this login is opened with a short PIN, which only an own-record login may be. */
    public function usesPin(): bool
    {
        return $this->sign_in_with === 'pin';
    }

    /** A doctor's own OPD consultation fee, when they have one. Asked for by name; never part of a list by itself. */
    public function getOpdFeeAttribute(): ?float
    {
        return $this->role === Role::Doctor ? \App\Support\DoctorFee::of($this->id)?->price : null;
    }

    public function getHasSignatureAttribute(): bool
    {
        return filled($this->signature_path);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
