<?php

namespace App\Calendar;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Converts between AD (Gregorian) dates and Bikram Sambat dates using the
 * verified month-length table in resources/calendar/bikram-sambat.php.
 *
 * Dates only (no time of day). AD dates are handled as calendar dates in UTC
 * so there are no DST or time-zone shifts. Outside the table's range every
 * method throws OutOfCalendarRange rather than guessing.
 */
final class BsCalendar
{
    /** Nepal's fiscal year runs Shrawan 1 to the last day of Asar. */
    public const FISCAL_YEAR_FIRST_MONTH = 4;

    /** @var array<int, list<int>> */
    private array $months;

    /** @var array<int, int> days from the anchor to Baisakh 1 of each year */
    private array $yearOffsets = [];

    private DateTimeImmutable $anchor;

    private int $firstYear;

    private int $lastYear;

    public function __construct(?array $data = null)
    {
        $data ??= require resource_path('calendar/bikram-sambat.php');
        $this->months = $data['months'];
        $this->firstYear = $data['anchor']['bs_year'];
        $this->lastYear = array_key_last($this->months);
        $this->anchor = new DateTimeImmutable($data['anchor']['ad'], new DateTimeZone('UTC'));

        $offset = 0;
        for ($year = $this->firstYear; $year <= $this->lastYear; $year++) {
            $lengths = $this->months[$year] ?? throw new InvalidArgumentException("Calendar data has no year {$year}.");
            if (count($lengths) !== 12 || min($lengths) < 29 || max($lengths) > 32) {
                throw new InvalidArgumentException("Calendar data for {$year} is malformed.");
            }
            $this->yearOffsets[$year] = $offset;
            $offset += array_sum($lengths);
        }
    }

    public function firstDate(): BsDate
    {
        return new BsDate($this->firstYear, 1, 1);
    }

    public function lastDate(): BsDate
    {
        return new BsDate($this->lastYear, 12, $this->months[$this->lastYear][11]);
    }

    public function daysInMonth(int $year, int $month): int
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("Invalid BS month {$month}.");
        }

        return $this->months[$year][$month - 1] ?? throw new OutOfCalendarRange("No verified calendar data for BS {$year}.");
    }

    public function isValid(int $year, int $month, int $day): bool
    {
        return isset($this->months[$year]) && $month >= 1 && $month <= 12
            && $day >= 1 && $day <= $this->months[$year][$month - 1];
    }

    /** AD date (string Y-m-d or DateTimeInterface; only the calendar date is used) to BS. */
    public function toBs(DateTimeInterface|string $ad): BsDate
    {
        $date = $this->calendarDate($ad);
        $days = (int) $this->anchor->diff($date)->format('%r%a');

        if ($days < 0) {
            throw new OutOfCalendarRange("{$date->format('Y-m-d')} is before the supported calendar range.");
        }

        for ($year = $this->firstYear; $year <= $this->lastYear; $year++) {
            $yearLength = array_sum($this->months[$year]);
            if ($days < $yearLength) {
                foreach ($this->months[$year] as $index => $length) {
                    if ($days < $length) {
                        return new BsDate($year, $index + 1, $days + 1);
                    }
                    $days -= $length;
                }
            }
            $days -= $yearLength;
        }

        throw new OutOfCalendarRange("{$date->format('Y-m-d')} is after the supported calendar range (ends BS {$this->lastDate()}).");
    }

    public function toAd(int $year, int $month, int $day): DateTimeImmutable
    {
        if (! isset($this->months[$year])) {
            throw new OutOfCalendarRange("No verified calendar data for BS {$year}.");
        }
        if (! $this->isValid($year, $month, $day)) {
            throw new InvalidArgumentException(sprintf('Invalid BS date %04d-%02d-%02d.', $year, $month, $day));
        }

        $days = $this->yearOffsets[$year] + array_sum(array_slice($this->months[$year], 0, $month - 1)) + $day - 1;

        return $this->anchor->modify("+{$days} days");
    }

    /** Parse "YYYY-MM-DD" in BS. */
    public function parseBs(string $value): BsDate
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || ! $this->isValid((int) $m[1], (int) $m[2], (int) $m[3])) {
            throw new InvalidArgumentException("Invalid or unsupported BS date '{$value}'.");
        }

        return new BsDate((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    /**
     * AD start and end of the fiscal year that starts in Shrawan of $startYear.
     * The end is null when the calendar does not yet cover the following Asar.
     *
     * @return array{start: DateTimeImmutable, end: ?DateTimeImmutable}
     */
    public function fiscalYearBounds(int $startYear): array
    {
        $start = $this->toAd($startYear, self::FISCAL_YEAR_FIRST_MONTH, 1);
        $endYear = $startYear + 1;
        $lastMonth = self::FISCAL_YEAR_FIRST_MONTH - 1;
        $end = isset($this->months[$endYear])
            ? $this->toAd($endYear, $lastMonth, $this->months[$endYear][$lastMonth - 1])
            : null;

        return ['start' => $start, 'end' => $end];
    }

    private function calendarDate(DateTimeInterface|string $ad): DateTimeImmutable
    {
        $ymd = $ad instanceof DateTimeInterface ? $ad->format('Y-m-d') : $ad;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));

        if ($date === false || $date->format('Y-m-d') !== $ymd) {
            throw new InvalidArgumentException("Invalid AD date '{$ymd}'.");
        }

        return $date;
    }
}
