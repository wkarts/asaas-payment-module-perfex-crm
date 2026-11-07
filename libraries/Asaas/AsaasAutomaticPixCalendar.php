<?php

declare(strict_types=1);

namespace Asaas;

/** Banking dates in Brazil; Asaas remains authoritative when accepting an instruction. */
final class AsaasAutomaticPixCalendar
{
    private \DateTimeZone $zone;
    public function __construct(private array $additionalHolidays = []) { $this->zone = new \DateTimeZone('America/Sao_Paulo'); }
    public function date(string $date): \DateTimeImmutable
    {
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->zone);
        if (!$value || $value->format('Y-m-d') !== $date) { throw new \InvalidArgumentException('invalid_due_date'); }
        return $value;
    }
    public function businessDays(string $today, string $dueDate): int
    {
        $start = $this->date($today); $end = $this->date($dueDate);
        if ($end <= $start) { return 0; }
        if ($end > $start->modify('+2 years')) { return 999; }
        $count = 0;
        for ($day = $start->modify('+1 day'); $day <= $end; $day = $day->modify('+1 day')) {
            if ($this->isBusinessDay($day)) { ++$count; }
        }
        return $count;
    }
    public function isBusinessDay(\DateTimeImmutable $date): bool
    {
        $year = (int) $date->format('Y'); $key = $date->format('Y-m-d');
        if ((int) $date->format('N') >= 6 || in_array($key, $this->additionalHolidays, true)) { return false; }
        $holidays = ['01-01', '04-21', '05-01', '09-07', '10-12', '11-02', '11-15', '12-25'];
        if ($year >= 2024) { $holidays[] = '11-20'; }
        if (in_array($date->format('m-d'), $holidays, true)) { return false; }
        // Gregorian computus: no dependency on PHP's optional calendar extension.
        $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100;
        $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31); $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        $easter = $this->date(sprintf('%04d-%02d-%02d', $year, $month, $day));
        foreach ([-48, -47, -2, 60] as $offset) {
            if ($easter->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d') === $key) { return false; }
        }
        return true;
    }
    public function cycle(string $date, string $frequency, string $anchor): string
    {
        $due = $this->date($date); $start = $this->date($anchor);
        if ($due < $start) { throw new \InvalidArgumentException('invalid_due_date'); }
        if ($frequency === 'WEEKLY') { return 'w' . intdiv((int) $start->diff($due)->days, 7); }
        $months = ((int) $due->format('Y') - (int) $start->format('Y')) * 12 + (int) $due->format('m') - (int) $start->format('m');
        $interval = ['MONTHLY' => 1, 'QUARTERLY' => 3, 'SEMIANNUALLY' => 6, 'ANNUALLY' => 12][$frequency] ?? 0;
        if ($interval === 0) { throw new \InvalidArgumentException('invalid_frequency'); }
        return 'm' . intdiv($months, $interval);
    }
}
