<?php

namespace Tests\Feature;

use App\Calendar\BsCalendar;
use App\Calendar\BsDate;
use App\Calendar\OutOfCalendarRange;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Verifies the calendar table and conversions. No database. */
class BsCalendarTest extends TestCase
{
    private function calendar(): BsCalendar
    {
        return app(BsCalendar::class);
    }

    /**
     * BS/AD pairs from the Ministry of Home Affairs public holiday notice for
     * BS 2083 (published 2082-11-18), as listed in the Pradhan & Associates
     * alert of 2026-03-10. Plus the fiscal-year start 2083-04-01 (2026-07-17)
     * and the last day of 2083 (Chaitra 30 = 2027-04-13) from published calendars.
     */
    public static function officialPairs(): array
    {
        $pairs = [
            '2083-01-01' => '2026-04-14', '2083-01-18' => '2026-05-01', '2083-02-15' => '2026-05-29',
            '2083-05-12' => '2026-08-28', '2083-05-13' => '2026-08-29', '2083-05-19' => '2026-09-04',
            '2083-05-29' => '2026-09-14', '2083-06-03' => '2026-09-19', '2083-06-09' => '2026-09-25',
            '2083-06-18' => '2026-10-04', '2083-06-25' => '2026-10-11', '2083-06-31' => '2026-10-17',
            '2083-07-06' => '2026-10-23', '2083-07-22' => '2026-11-08', '2083-07-25' => '2026-11-11',
            '2083-07-26' => '2026-11-12', '2083-07-29' => '2026-11-15', '2083-08-17' => '2026-12-03',
            '2083-09-09' => '2026-12-24', '2083-09-10' => '2026-12-25', '2083-09-15' => '2026-12-30',
            '2083-09-27' => '2027-01-11', '2083-10-01' => '2027-01-15', '2083-10-16' => '2027-01-30',
            '2083-10-24' => '2027-02-07', '2083-10-28' => '2027-02-11', '2083-11-07' => '2027-02-19',
            '2083-11-22' => '2027-03-06', '2083-11-24' => '2027-03-08', '2083-11-25' => '2027-03-09',
            '2083-12-07' => '2027-03-21',
            '2083-04-01' => '2026-07-17', '2083-12-30' => '2027-04-13',
            '2000-01-01' => '1943-04-14',
        ];

        return array_map(fn ($bs, $ad) => [$bs, $ad], array_keys($pairs), $pairs);
    }

    #[DataProvider('officialPairs')]
    public function test_converts_known_pairs_both_ways(string $bs, string $ad): void
    {
        $this->assertSame($bs, (string) $this->calendar()->toBs($ad));
        [$y, $m, $d] = array_map('intval', explode('-', $bs));
        $this->assertSame($ad, $this->calendar()->toAd($y, $m, $d)->format('Y-m-d'));
    }

    public function test_every_day_in_range_round_trips_and_is_consecutive(): void
    {
        $calendar = $this->calendar();
        $first = $calendar->toAd(2000, 1, 1);
        $last = $calendar->toAd(2083, 12, 30);
        $previous = null;

        for ($date = $first; $date <= $last; $date = $date->modify('+1 day')) {
            $bs = $calendar->toBs($date);
            $this->assertSame($date->format('Y-m-d'), $calendar->toAd($bs->year, $bs->month, $bs->day)->format('Y-m-d'));
            if ($previous !== null) {
                $nextExpected = $previous->day < $calendar->daysInMonth($previous->year, $previous->month)
                    ? [$previous->year, $previous->month, $previous->day + 1]
                    : ($previous->month < 12 ? [$previous->year, $previous->month + 1, 1] : [$previous->year + 1, 1, 1]);
                $this->assertSame($nextExpected, [$bs->year, $bs->month, $bs->day]);
            }
            $previous = $bs;
        }
    }

    public function test_range_is_bs_2000_to_2083_and_fails_closed_outside(): void
    {
        $calendar = $this->calendar();
        $this->assertSame('2000-01-01', (string) $calendar->firstDate());
        $this->assertSame('2083-12-30', (string) $calendar->lastDate());

        foreach (['1943-04-13', '2027-04-14'] as $ad) {
            try {
                $calendar->toBs($ad);
                $this->fail("{$ad} should be out of range");
            } catch (OutOfCalendarRange) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(OutOfCalendarRange::class);
        $calendar->toAd(2084, 1, 1);
    }

    public function test_rejects_impossible_dates(): void
    {
        $this->assertFalse($this->calendar()->isValid(2083, 7, 31)); // Kartik 2083 has 30 days
        $this->expectException(InvalidArgumentException::class);
        $this->calendar()->toAd(2083, 7, 31);
    }

    public function test_rejects_malformed_ad_dates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calendar()->toBs('2026-02-30');
    }

    public function test_time_of_day_and_timezone_do_not_shift_the_date(): void
    {
        $late = new DateTimeImmutable('2026-10-16 23:59:59', new \DateTimeZone('Asia/Kathmandu'));

        $this->assertSame('2083-06-30', (string) $this->calendar()->toBs($late));
    }

    public function test_fiscal_year_bounds(): void
    {
        $fy8283 = $this->calendar()->fiscalYearBounds(2082);
        $this->assertSame('2025-07-17', $fy8283['start']->format('Y-m-d'));
        $this->assertSame('2026-07-16', $fy8283['end']->format('Y-m-d'));

        // 2083/84 ends in Asar 2084, which is not in the verified calendar yet.
        $fy8384 = $this->calendar()->fiscalYearBounds(2083);
        $this->assertSame('2026-07-17', $fy8384['start']->format('Y-m-d'));
        $this->assertNull($fy8384['end']);
    }

    public function test_fiscal_year_of_a_date(): void
    {
        $this->assertSame(2082, (new BsDate(2083, 3, 32))->fiscalYearStart()); // last day of Asar
        $this->assertSame(2083, (new BsDate(2083, 4, 1))->fiscalYearStart());  // Shrawan 1
    }

    public function test_parse_bs(): void
    {
        $this->assertSame('2083-06-30', (string) $this->calendar()->parseBs('2083-06-30'));
        $this->expectException(InvalidArgumentException::class);
        $this->calendar()->parseBs('2083-13-01');
    }
}
