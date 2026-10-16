<?php

namespace App\Members;

/**
 * Keyed hash of an identity number, so duplicates can be found without
 * storing the number in plain text. Keyed with a key derived from APP_KEY:
 * rotating APP_KEY requires recomputing these hashes (and re-encrypting KYC).
 */
final class BlindIndex
{
    public static function of(string $kind, ?string $value, string $qualifier = ''): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        // Keep separators: "1-201" and "12-01" are different numbers.
        $normalised = mb_strtoupper(preg_replace('/\s+/', ' ', trim($value)));
        $key = hash_hmac('sha256', 'sahakarios-kyc-blind-index-v1', (string) config('app.key'));

        return hash_hmac('sha256', $kind.'|'.mb_strtoupper(trim($qualifier)).'|'.$normalised, $key);
    }
}
