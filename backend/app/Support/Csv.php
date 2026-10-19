<?php

namespace App\Support;

use RuntimeException;

final class Csv
{
    /**
     * Reads a CSV whose first line must be exactly $header (case-insensitive).
     * A UTF-8 byte order mark (Excel "CSV UTF-8") is ignored.
     *
     * @return list<array<string, string>>
     */
    public static function read(string $path, array $header): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Cannot read {$path}.");
        }
        $handle = fopen($path, 'r');
        $rows = [];
        $first = fgetcsv($handle, escape: '');
        $first = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $first ?: []);
        if ($first !== $header) {
            fclose($handle);
            throw new RuntimeException('CSV header must be: '.implode(',', $header));
        }
        while (($line = fgetcsv($handle, escape: '')) !== false) {
            if ($line === [null]) {
                continue; // blank line
            }
            $rows[] = array_combine($header, array_map(fn ($v) => trim((string) $v), array_slice(array_pad($line, count($header), ''), 0, count($header))));
        }
        fclose($handle);

        return $rows;
    }

    /** Writes rows (lists of scalars) as CSV with a header line. */
    public static function write(string $path, array $header, iterable $rows): void
    {
        $handle = fopen($path, 'w') ?: throw new RuntimeException("Cannot write {$path}.");
        fputcsv($handle, $header, escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), escape: '');
        }
        fclose($handle);
    }
}
