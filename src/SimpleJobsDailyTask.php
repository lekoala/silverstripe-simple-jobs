<?php

namespace LeKoala\SimpleJobs;

use SilverStripe\ORM\DataList;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\CronTask\Interfaces\CronTask;
use SilverStripe\SessionManager\Models\LoginSession;

/**
 */
class SimpleJobsDailyTask implements CronTask, SimpleJobsDescription
{
    use Configurable;

    public static function getJobTitle(): string
    {
        return 'Session collection service';
    }

    public static function getJobCategory(): string
    {
        return SimpleJobsDescription::SYSTEM;
    }

    public static function getJobDescription(): string
    {
        return 'Collects expired session in the database';
    }

    /**
     * Logic extracted roughly from GarbageCollectionService that doesn't expose what it collects
     * so we need to run our own query
     */
    protected static function getExpiredSessions(): DataList
    {
        $maxAge = LoginSession::getMaxAge();
        $nowTs = DBDatetime::now()->getTimestamp();
        $now = date('Y-m-d H:i:s', $nowTs);

        $case1 = "(LastAccessed < '$maxAge' AND Persistent = 0)";
        $case2 = "(Persistent = 1 AND RememberLoginHash.ExpiryDate < '$now')";
        $case3 = "(LastAccessed < '$maxAge' AND Persistent = 1 AND RememberLoginHash.ExpiryDate IS NULL)";

        $list = LoginSession::get()->leftJoin('RememberLoginHash', 'RememberLoginHash.LoginSessionID = LoginSession.ID');
        $list = $list->where("$case1 OR $case2 OR $case3");
        return $list;
    }

    public static function getJobRecords(): ?DataList
    {
        return null;

        //@link https://github.com/silverstripe/silverstripe-session-manager/issues/178
        // return self::getExpiredSessions();
    }

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

    public static function IsDisabled(): bool
    {
        $config = static::config();
        $sessionGarbageCollector = \SilverStripe\SessionManager\Services\GarbageCollectionService::class;
        if (class_exists($sessionGarbageCollector) && $config->clean_sessions) {
            return false;
        }
        return true;
    }

    /**
     * When this script is supposed to run the CronTaskController will execute
     * process().
     *
     * @return mixed
     */
    public function process()
    {
        if (self::IsDisabled()) {
            return 'disabled';
        }

        // Hard to know what this will be doing
        // @link https://github.com/silverstripe/silverstripe-session-manager/issues/160
        \SilverStripe\SessionManager\Services\GarbageCollectionService::singleton()->collect();
        return 'done';
    }
}
