<?php

namespace App\Support;

use App\Models\Bill;
use Illuminate\Validation\ValidationException;

/**
 * A discount at the front desk is money the hospital gives up, so each discounted bill says why it was
 * given and who allowed it. Asked for once per bill: a second discount on the same bill keeps the first
 * answer unless a new one is sent.
 */
class DiscountWhy
{
    public const RULES = [
        'discount_reason' => ['nullable', 'string', 'max:255'],
        'discount_allowed_by' => ['nullable', 'string', 'max:100'],
    ];

    /**
     * Refuses a discount that comes with no reason or no name, then writes both on the bill.
     *
     * @param  array<string, mixed>  $data
     */
    public static function need(Bill $bill, array $data): void
    {
        $reason = trim((string) ($data['discount_reason'] ?? '')) ?: $bill->discount_reason;
        $by = trim((string) ($data['discount_allowed_by'] ?? '')) ?: $bill->discount_allowed_by;

        $missing = array_filter([
            'discount_reason' => $reason ? null : 'Say why the discount is given.',
            'discount_allowed_by' => $by ? null : 'Say who allowed the discount.',
        ]);
        if ($missing) {
            throw ValidationException::withMessages($missing);
        }

        $bill->forceFill(['discount_reason' => $reason, 'discount_allowed_by' => $by])->save();
    }

    /** " · reason: staff family · allowed by Dr Rao", for the activity log. */
    public static function note(Bill $bill): string
    {
        return $bill->discount_reason ? " · reason: {$bill->discount_reason} · allowed by {$bill->discount_allowed_by}" : '';
    }
}
