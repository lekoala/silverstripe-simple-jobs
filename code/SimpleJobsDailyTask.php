<?php

namespace LeKoala\SimpleJobs;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\CronTask\Interfaces\CronTask;

/**
 */
class SimpleJobsDailyTask implements CronTask
{
    use Configurable;

    /**
     * Return a string for a CRON expression. If a "falsy" value is returned, the CronTaskController will assume the
     * CronTask is disabled.
     *
     * @return string
     */
    public function getSchedule()
    {
        return SimpleJobsSchedules::EVERY_DAY;
    }

    /**
     * When this script is supposed to run the CronTaskController will execute
     * process().
     *
     * @return void
     */
    public function process()
    {
        $config = static::config();

        $sessionGarbageCollector = \SilverStripe\SessionManager\Services\GarbageCollectionService::class;

        if (class_exists($sessionGarbageCollector) && $config->clean_sessions) {
            \SilverStripe\SessionManager\Services\GarbageCollectionService::singleton()->collect();
        }
    }
}
