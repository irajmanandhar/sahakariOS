<?php

namespace App\Calendar;

use Stringable;

/** A Bikram Sambat calendar date. Validated by BsCalendar; construct via BsCalendar. */
final readonly class BsDate implements Stringable
{
    public const MONTH_NAMES = [
        1 => 'Baisakh', 'Jestha', 'Asar', 'Shrawan', 'Bhadra', 'Asoj',
        'Kartik', 'Mangsir', 'Poush', 'Magh', 'Falgun', 'Chaitra',
    ];

    public function __construct(public int $year, public int $month, public int $day) {}

    public function monthName(): string
    {
        return self::MONTH_NAMES[$this->month];
    }

    /** Fiscal year this date belongs to, named by its starting BS year (Shrawan..Asar). */
    public function fiscalYearStart(): int
    {
        return $this->month >= BsCalendar::FISCAL_YEAR_FIRST_MONTH ? $this->year : $this->year - 1;
    }

    public function __toString(): string
    {
        return sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
    }
}
