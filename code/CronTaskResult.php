<?php

namespace LeKoala\SimpleJobs;

use SilverStripe\ORM\DataObject;

/**
 * Store the result of a cron task
 *
 * @author Koala
 * @property string $TaskClass
 * @property string $Result
 * @property boolean $Failed
 * @property boolean $ForcedRun
 */
class CronTaskResult extends DataObject
{
    private static $table_name = 'CronTaskResult';
    private static $db = array(
        'TaskClass' => 'Varchar(255)',
        'Result' => 'Text',
        'Failed' => 'Boolean',
        'ForcedRun' => 'Boolean',
        'StartDate' => 'Datetime',
        'EndDate' => 'Datetime',
        'TimeToExecute' => 'Int',
    );
    private static $default_sort = 'Created DESC';

    public function Status()
    {
        $status = "Task {$this->TaskClass}";
        if ($this->Failed) {
            $status .= " failed to run";
        } else {
            $status .= " ran successfully";
        }
        $status .= " at " . $this->Created;
        if ($this->ForcedRun) {
            $status .= ' (forced run)';
        }
        return $status;
    }

    public function PrettyResult()
    {
        return self::PrettifyResult($this->Result);
    }

    public static function PrettifyResult($result)
    {
        if ($result === false) {
            $result = 'Task failed';
        }
        if (is_object($result)) {
            $result = print_r($result, true);
        } elseif (is_array($result)) {
            $result = json_encode($result);
        }
        return '<pre> ' . $result . '</pre>';
    }
}
