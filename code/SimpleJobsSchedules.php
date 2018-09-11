<?php
namespace LeKoala\SimpleJobs;

/**
 * A common set of schedule
 *
 * If you need more, visit http://www.cronmaker.com/
 *
 * # +--------- Minute (0-59)
 * # | +------- Hour (0-23)
 * # | | +----- Day Of Month (1-31)
 * # | | | +--- Month (1-12)
 * # | | | | +- Day Of Week (0-6) (Sunday = 0)
 *
 * Do every X intervals: *[fw_slash]X  -> Example: *[fw_slash]15 * * * *  Is every 15 minutes
 * Multiple Values Use Commas: 3,12,47
 * eg daily -> 0 0 * * *; weekly -> 0 0 * * 0; monthly ->0 0 1 * *;
 *
 * @author Koala
 */
class SimpleJobsSchedules
{

    // Some predefined constants or use custom methods below
    const EVERY_TIME = "* * * * *";
    const EVERY_FIVE_MIN = "*/5 * * * *";
    const EVERY_HOUR = "0 * * * *"; // at 1, 2, 3...
    const EVERY_DAY = "0 3 * * *"; // at 3 in the morning
    const EVERY_WEEK = "0 3 * * 1"; // on Monday (Sunday = 0 or 7)
    const EVERY_MONTH = "0 3 1 * *"; // first day of each month
    const EVERY_YEAR = "0 3 1 1 *"; // every first january

    /**
     * Every day on hour H
     *
     * @param int $h
     * @return string
     */
    public static function everyDay($h = 3)
    {
        return "0 $h * * *";
    }

    /**
     * Every week on day D (Sunday = 0 or 7) at H
     *
     * @param int $day
     * @param int $h
     * @return string
     */
    public static function everyWeek($day = 1, $h = 3)
    {
        return "0 $h * * $day";
    }
}
