<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

/*
 * One place for the shop's local time. Timestamps are stored UTC and displayed
 * in Asia/Karachi. Pakistan is a fixed UTC+5 with no daylight saving, so a fixed
 * offset is used deliberately instead of the IANA zone: it is fully
 * deterministic, which matters because the business date it produces keys
 * sessions and invoice numbers.
 *
 * Ported from shared/utils/timezone.py.
 */
class BusinessDate
{
    public const OFFSET = '+05:00';

    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::OFFSET);
    }

    public static function nowUtc(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    /** Render a stored UTC timestamp in Karachi local time. */
    public static function toLocal(CarbonInterface|string $utc): CarbonImmutable
    {
        $value = $utc instanceof CarbonInterface
            ? CarbonImmutable::instance($utc->toDateTime())
            : CarbonImmutable::parse($utc, 'UTC');

        return $value->setTimezone(self::zone());
    }

    /**
     * The shop's calendar date for a UTC instant. A session opened at 11:50 PM
     * and one opened at 12:10 AM sit on different business dates; both are
     * computed from Karachi local time, never from UTC or the machine clock.
     */
    public static function for(CarbonInterface|string|null $utc = null): CarbonImmutable
    {
        return self::toLocal($utc ?? self::nowUtc())->startOfDay();
    }

    /** The current business date as a Y-m-d string. */
    public static function today(): string
    {
        return self::for()->toDateString();
    }
}
