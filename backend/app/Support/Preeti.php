<?php

namespace App\Support;

/**
 * Converts text typed in the legacy Preeti font (ASCII keys drawn as
 * Devanagari glyphs) to Unicode Nepali. Covers the common Preeti layout;
 * rare ligatures may come out wrong, so imports keep the original text too.
 */
final class Preeti
{
    /** Multi-key glyphs, replaced before single keys. */
    private const PAIRS = [
        'km' => 'फ', 'em' => 'झ', 'Qm' => 'क्त', 'qm' => 'क्र', 'O{' => 'ई', 'If' => 'क्ष', '0f' => 'ण', 'Tf' => 'त',
    ];

    private const KEYS = [
        'a' => 'ब', 'b' => 'द', 'c' => 'अ', 'd' => 'म', 'e' => 'भ', 'f' => 'ा', 'g' => 'न', 'h' => 'ज', 'i' => 'ष्',
        'j' => 'व', 'k' => 'प', 'l' => "\u{E000}", 'm' => 'ु', 'n' => 'ल', 'o' => 'य', 'p' => 'उ', 'q' => 'त्र', 'r' => 'च',
        's' => 'क', 't' => 'त', 'u' => 'ग', 'v' => 'ख', 'w' => 'ध', 'x' => 'ह', 'y' => 'थ', 'z' => 'श',
        'A' => 'ब्', 'B' => 'द्य', 'C' => 'ऋ', 'D' => 'म्', 'E' => 'भ्', 'F' => 'ँ', 'G' => 'न्', 'H' => 'ज्', 'I' => 'क्ष्',
        'J' => 'व्', 'K' => 'प्', 'L' => 'ी', 'M' => 'ः', 'N' => 'ल्', 'O' => 'इ', 'P' => 'ए', 'Q' => 'त्त', 'R' => 'च्',
        'S' => 'क्', 'T' => 'त्', 'U' => 'ग्', 'V' => 'ख्', 'W' => 'ध्', 'X' => 'ह्', 'Y' => 'थ्', 'Z' => 'श्',
        '0' => 'ण्', '1' => 'ज्ञ', '2' => 'द्द', '3' => 'घ', '4' => 'द्ध', '5' => 'छ', '6' => 'ट', '7' => 'ठ', '8' => 'ड', '9' => 'ढ',
        ')' => '०', '!' => '१', '@' => '२', '#' => '३', '$' => '४', '%' => '५', '^' => '६', '&' => '७', '*' => '८', '(' => '९',
        '`' => 'ञ्', '~' => 'ञ्', "'" => 'ु', '"' => 'ू', '/' => 'र', '?' => 'रु', '[' => 'ृ', ']' => 'े', '\\' => '्',
        '{' => "\u{E001}", '}' => 'ै', '|' => '्र', '«' => '्र', ';' => 'स', ':' => 'स्', '+' => 'ं', '=' => '.', '.' => '।',
        '>' => 'श्र', '<' => '?', '-' => '(', '_' => ')',
    ];

    /** English words the old workbooks mix with Preeti text (kept as typed). */
    private const ENGLISH = ['a/c', 'no', 'cash', 'member', 'renew', 'charge', 'interest', 'tax', 'total', 'balance', 'loan', 'fine',
        'opening', 'transfer', 'cheque', 'by', 'to', 'from', 'bank', 'deposit', 'withdraw', 'withdrawal', 'share', 'fee', 'rate', 'date',
        'debit', 'credit', 'service', 'penalty', 'principal', 'saving', 'savings', 'account', 'closing', 'close', 'paid', 'received'];

    public static function toUnicode(string $text): string
    {
        $s = strtr($text, self::PAIRS);
        $s = strtr($s, self::KEYS);
        $s = str_replace('्ा', '', $s); // half letter + aa sign = full letter
        $consonant = '(?:[क-ह]्)*[क-ह]';
        $s = preg_replace("/\u{E000}({$consonant})/u", '$1ि', $s);           // i-kar is typed before its consonant cluster
        $s = preg_replace("/({$consonant}[ािीुूृेैोौंँ]*)\u{E001}/u", 'र्$1', $s); // reph is typed after its syllable
        $s = strtr($s, ["\u{E000}" => 'ि', "\u{E001}" => 'र्', 'अा' => 'आ']);

        return strtr($s, ['ाे' => 'ो', 'ाै' => 'ौ', 'आे' => 'ओ', 'आै' => 'औ', 'एे' => 'ऐ']);
    }

    /** Converts only if the text is not plain English (best effort: an allowlist of words seen in the old workbooks). */
    public static function toUnicodeIfPreeti(string $text): string
    {
        $words = preg_split('/[^A-Za-z\/]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return $words !== [] && array_diff($words, self::ENGLISH) === [] ? $text : self::toUnicode($text);
    }
}
