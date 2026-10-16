<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Gap-free per-cooperative numbering (account numbers, loan numbers). Call inside a transaction. */
final class NumberSequence
{
    public static function next(int $cooperativeId, string $name): int
    {
        DB::insert('INSERT INTO number_sequences (cooperative_id, name, last_number) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE last_number = last_number', [$cooperativeId, $name]);
        $number = DB::selectOne('SELECT last_number FROM number_sequences WHERE cooperative_id = ? AND name = ? FOR UPDATE', [$cooperativeId, $name])->last_number + 1;
        DB::update('UPDATE number_sequences SET last_number = ? WHERE cooperative_id = ? AND name = ?', [$number, $cooperativeId, $name]);

        return $number;
    }
}
