<?php
// organizer/src/class/PostlistePeriod.php

/**
 * The period part of a `postliste:<period>` thread label.
 *
 * norske-postlister.no requests postjournals per period and labels each thread
 * with the period it covers. Accepted forms (ISO week, month, year, and closed
 * ranges of months or years):
 *
 *   2026-W38              ISO week
 *   2026-09               month
 *   2025                  year
 *   2025-01--2025-06      month range, inclusive
 *   2011--2021            year range, inclusive
 *
 * Old hand-made threads carry e.g. `postliste:2011-2021` (single dash). That
 * form is not accepted for new threads and is not renamed - see the spec.
 */
class PostlistePeriod {
    const LABEL_PREFIX = 'postliste:';

    public string $period;
    public DateTimeImmutable $from;
    public DateTimeImmutable $to;

    private function __construct(string $period, DateTimeImmutable $from, DateTimeImmutable $to) {
        $this->period = $period;
        $this->from = $from;
        $this->to = $to;
    }

    public static function isValid(string $period): bool {
        return self::tryParse($period) !== null;
    }

    /**
     * @return ?PostlistePeriod null when the period is malformed or from > to
     */
    public static function tryParse(string $period): ?PostlistePeriod {
        $utc = new DateTimeZone('UTC');

        if (preg_match('/^(\d{4})-W(\d{2})$/', $period, $m)) {
            $year = (int)$m[1];
            $week = (int)$m[2];
            if ($week < 1 || $week > 53) {
                return null;
            }
            $from = (new DateTimeImmutable('now', $utc))->setISODate($year, $week, 1)->setTime(0, 0, 0);
            // setISODate() silently rolls week 53 over into the next year when
            // the year has only 52 ISO weeks; reject that instead of accepting a
            // label that names a week which does not exist.
            if ((int)$from->format('o') !== $year || (int)$from->format('W') !== $week) {
                return null;
            }
            return new PostlistePeriod($period, $from, $from->modify('+6 days'));
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $period, $m)) {
            $from = self::monthStart((int)$m[1], (int)$m[2], $utc);
            if ($from === null) {
                return null;
            }
            return new PostlistePeriod($period, $from, self::monthEnd($from));
        }

        if (preg_match('/^(\d{4})$/', $period, $m)) {
            $from = new DateTimeImmutable($m[1] . '-01-01', $utc);
            return new PostlistePeriod($period, $from, new DateTimeImmutable($m[1] . '-12-31', $utc));
        }

        if (preg_match('/^(\d{4})-(\d{2})--(\d{4})-(\d{2})$/', $period, $m)) {
            $from = self::monthStart((int)$m[1], (int)$m[2], $utc);
            $toStart = self::monthStart((int)$m[3], (int)$m[4], $utc);
            if ($from === null || $toStart === null || $from > $toStart) {
                return null;
            }
            return new PostlistePeriod($period, $from, self::monthEnd($toStart));
        }

        if (preg_match('/^(\d{4})--(\d{4})$/', $period, $m)) {
            if ((int)$m[1] > (int)$m[2]) {
                return null;
            }
            $from = new DateTimeImmutable($m[1] . '-01-01', $utc);
            return new PostlistePeriod($period, $from, new DateTimeImmutable($m[2] . '-12-31', $utc));
        }

        return null;
    }

    /**
     * The first `postliste:<period>` label on a thread, parsed. Labels with a
     * malformed period (e.g. the legacy `postliste:2011-2021`) are skipped.
     */
    public static function fromLabels(array $labels): ?PostlistePeriod {
        foreach ($labels as $label) {
            if (str_starts_with($label, self::LABEL_PREFIX)) {
                $parsed = self::tryParse(substr($label, strlen(self::LABEL_PREFIX)));
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }
        return null;
    }

    /** Norwegian date formatting for email templates: dd.mm.yyyy */
    public function fromFormatted(): string {
        return $this->from->format('d.m.Y');
    }

    public function toFormatted(): string {
        return $this->to->format('d.m.Y');
    }

    private static function monthStart(int $year, int $month, DateTimeZone $tz): ?DateTimeImmutable {
        if ($month < 1 || $month > 12) {
            return null;
        }
        return new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $tz);
    }

    private static function monthEnd(DateTimeImmutable $monthStart): DateTimeImmutable {
        return $monthStart->modify('last day of this month');
    }
}
