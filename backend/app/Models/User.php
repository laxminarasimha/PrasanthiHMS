<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'branch_id', 'role', 'gender', 'phone', 'department', 'signature_text', 'is_active', 'pad_hidden_sections', 'hidden_tabs', 'appearance', 'pad_section_order', 'rx_settings', 'permissions', 'signature_path', 'seal_path', 'availability'])]
#[Hidden(['password', 'remember_token', 'signature_path', 'seal_path', 'permissions'])]
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
        'canConsult' => ['Doctor: visit pad', 'Open the visit pad, write notes and prescriptions, and use the doctor library.'],
        'canRunLab' => ['Lab', 'Enter and approve lab results, and edit lab tests, packages and preferences.'],
        'canRunPharmacy' => ['Pharmacy', 'Sell medicines, receive stock, and edit medicines, bills and invoices.'],
        'canDeleteBills' => ['Delete pharmacy bills', 'Delete a pharmacy bill, which restocks it and refunds the money.'],
        'canWorkIpd' => ['IPD', 'Admit patients, add IPD charges, take IPD payments and discharge.'],
        'canApproveIpd' => ['Approve IPD summaries and bills', 'Approve a discharge summary or an IPD bill, which locks it against changes.'],
        'canViewReports' => ['Reports', "See the hospital's billing, collection and activity reports."],
        'canCloseDay' => ['Close the day', 'Count the cash and close a day’s accounts, which locks that day’s bills and payments.'],
        'canManageSetup' => ['Price lists and setup', 'Change services, OPD hours, beds and IPD services.'],
        'canManageStaff' => ['Staff and permissions', 'Add staff, reset passwords and change what each person may do.'],
    ];

    /**
     * Whether this person may do something: their own setting if one was made, otherwise what their role allows.
     * Admins always keep everything, so the hospital cannot lock itself out.
     */
    public function allows(string $ability): bool
    {
        $default = method_exists($this->role, $ability) && $this->role->{$ability}();

        return $this->role === Role::Admin ? $default : (bool) ($this->permissions[$ability] ?? $default);
    }

    /**
     * @return array<string, bool>
     */
    public function getAbilitiesAttribute(): array
    {
        return collect(self::ABILITIES)->map(fn ($label, $ability) => $this->allows($ability))->all();
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
