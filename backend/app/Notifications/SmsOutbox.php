<?php

namespace App\Notifications;

use App\Accounting\Money;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Member SMS, written to the outbox inside the caller's transaction (so a
 * rolled-back transaction never sends a message). Delivered by `sms:send`.
 * Messages never contain full account numbers, balances of other accounts or
 * identity numbers.
 */
class SmsOutbox
{
    public function queue(Member $member, string $body): void
    {
        if (empty($member->phone)) {
            return; // no phone on file: nothing to send
        }
        DB::table('sms_messages')->insert([
            'cooperative_id' => $member->cooperative_id, 'member_id' => $member->id, 'phone' => $member->phone,
            'body' => mb_substr($body, 0, 480), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** "…34": last two digits only. */
    public static function mask(int|string $number): string
    {
        return '…'.substr((string) $number, -2);
    }

    /** "Rs 12,34,567.00": exact string formatting with Nepali lakh/crore grouping (no floats). */
    public static function rs(string $amount): string
    {
        [$whole, $paisa] = explode('.', Money::format($amount));
        $sign = str_starts_with($whole, '-') ? '-' : '';
        $whole = ltrim($whole, '-');
        if (strlen($whole) > 3) {
            $head = substr($whole, 0, -3);
            $whole = preg_replace('/\B(?=(\d{2})+$)/', ',', $head).','.substr($whole, -3);
        }

        return "Rs {$sign}{$whole}.{$paisa}";
    }
}
