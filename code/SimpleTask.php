<?php

namespace LeKoala\SimpleJobs;

use Exception;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;

/**
 * A simple class to schedule function calls
 * They will be picked up by the SimpleJobsController automatically
 * and run if the RunDate is below current time
 *
 * We only run one task each call to avoid excessive usages
 * Expect some delays if you have many tasks!
 *
 * @property string $Task
 * @property boolean $Processed
 * @property boolean $Failed
 * @property string $ErrorMessage
 * @property int $TimeToExecute
 * @property int $CallsCount
 * @property int $SuccessCalls
 * @property int $ErrorCalls
 * @property string $RunDate
 */
class SimpleTask extends DataObject
{
    private static $table_name = 'SimpleTask'; // When using namespace, specify table name
    private static $db = [
        'Task' => 'Text',
        'Processed' => 'Boolean',
        'Failed' => 'Boolean',
        'ErrorMessage' => 'Varchar(191)',
        'TimeToExecute' => 'Int',
        'CallsCount' => 'Int',
        'SuccessCalls' => 'Int',
        'ErrorCalls' => 'Int',
        "RunDate" => "Datetime",
    ];
    private static $default_sort = "RunDate DESC";

    protected function onBeforeWrite()
    {
        parent::onBeforeWrite();

        // Run asap by default
        if (!$this->RunDate) {
            $this->RunDate = date('Y-m-d H:i:s');
        }

        $this->CallsCount = count($this->getTaskDetails());
    }

    /**
     * An array of entries
     * @return array
     */
    public function getTaskDetails()
    {
        if ($this->Task) {
            return json_decode($this->Task, JSON_OBJECT_AS_ARRAY);
        }
        return [];
    }

    /**
     * @return DataList|SimpleTask[]
     */
    public static function getTasksThatNeedToRun()
    {
        $time = date('Y-m-d H:i:s');
        return SimpleTask::get()->where("Processed = 0 AND RunDate <= '$time'");
    }

    /**
     * @return SimpleTask|null
     */
    public static function getNextTaskToRun()
    {
        return self::getTasksThatNeedToRun()->limit(1)->first();
    }

    /**
     * Append to the list of things to do for this class
     *
     * @param DataObject $class
     * @param string $method
     * @param array $params
     * @return array
     */
    public function addToTask(DataObject $class, $method, $params = [])
    {
        $details = $this->getTaskDetails();

        // Task details contain one entry per thing to do in a task
        // A task can do multiple calls
        $details[] = [
            'class' => get_class($class),
            'id' => $class->ID,
            'function' => $method,
            'parameters' => $params
        ];

        $this->Task = json_encode($details);

        return $details;
    }

    /**
     * @return bool
     */
    public function process()
    {
        if ($this->Processed) {
            throw new Exception("Already processed");
        }

        // If there was not enough time, mark the task as processed anyway
        // to avoid calling it again next time
        $this->Processed = true;
        $this->Failed = true;
        $this->ErrorMessage = "Task did not complete";
        $this->write();

        $st = time();

        $conn = DB::get_conn();
        $conn->transactionStart();

        $success = $errors = 0;
        try {
            $details = $this->getTaskDetails();

            foreach ($details as $entry) {
                $class = $entry['class'];
                $id = $entry['id'];
                $function = $entry['function'];
                $parameters = $entry['parameters'];

                $inst = DataObject::get_by_id($class, $id);
                if ($inst) {
                    $result = call_user_func_array([$inst, $function], $parameters);
                    if ($result !== false) {
                        $success++;
                    } else {
                        $errors++;
                    }
                }
            }
            $this->Failed = null;
            $this->ErrorMessage = null;
        } catch (Exception $ex) {
            $this->Failed = true;
            $this->ErrorMessage = $ex->getMessage();
        }

        $et = time();
        $tt = $et - $st;

        $this->SuccessCalls = $success;
        $this->ErrorCalls = $errors;
        $this->TimeToExecute = $tt;
        $this->write();

        $conn->transactionEnd();

        return $this->Failed;
    }
}
