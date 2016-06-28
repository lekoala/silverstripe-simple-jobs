<?php

/**
 * A common set of schedule
 *
 * If you need more, visit http://www.cronmaker.com/
 * 
 * @author Koala
 */
class SimpleJobsSchedules
{
    const EVERY_TIME     = "* * * * *";
    const EVERY_FIVE_MIN = "*/5 * * * *";
    const EVERY_HOUR     = "0 * * * *"; // at 1, 2, 3...
    const EVERY_DAY      = "0 3 * * *"; // at 3 in the morning
    const EVERY_WEEK     = "0 3 * * 1"; // on Monday (Sunday = 0 or 7)
    const EVERY_MONTH    = "0 3 1 * *"; // first day of each month
    const EVERY_YEAR     = "0 3 1 1 *"; // every first january

}