<?php

namespace LeKoala\SimpleJobs;

use Exception;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * A simple class to schedule function calls
 * They will be picked up by the SimpleJobsController automatically
 * and run if the RunDate is below current time
 *
 * We only run one task each call to avoid excessive usages
 * Expect some delays if you have many tasks!
 *
 * @property ?string $Name
 * @property ?string $Task
 * @property bool $Processed
 * @property bool $Failed
 * @property ?string $ErrorMessage
 * @property int $TimeToExecute
 * @property int $CallsCount
 * @property int $SuccessCalls
 * @property int $ErrorCalls
 * @property ?string $RunDate
 * @property int $OwnerID
 * @method \SilverStripe\Security\Member Owner()
 */
class SimpleTask extends DataObject
{

    /**
     * @var string
     */
    private static $singular_name = 'Scheduled Task';

    /**
     * @var string
     */
    private static $plural_name = 'Scheduled Tasks';

    /**
     * @var string
     */
    private static $table_name = 'SimpleTask'; // When using namespace, specify table name

    /**
     * @var array<string, string>
     */
    private static $db = [
        'Name' => 'Varchar(191)',
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

    /**
     * @var array<string, class-string>
     */
    private static $has_one = [
        'Owner' => Member::class,
    ];

    /**
     * @var string
     */
    private static $default_sort = "RunDate DESC";

    /**
     * @var array<string>
     */
    private static $summary_fields = [
        'Created', 'Name', 'Processed', 'Failed', 'TimeToExecute'
    ];

    /**
     * @return void
     */
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
     * @return array<string, mixed>
     */
    public function getTaskDetails()
    {
        if ($this->Task) {
            $result = json_decode($this->Task, true);
            if ($result) {
                return $result;
            }
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
        /** @var SimpleTask|null $task */
        $task = self::getTasksThatNeedToRun()->sort('RunDate ASC')->limit(1)->first();
        return $task;
    }

    /**
     * Append to the list of things to do for this class
     *
     * @param DataObject $class
     * @param string $method
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function addToTask(DataObject $class, $method, $params = [])
    {
        $details = $this->getTaskDetails();

        // If no name is set, assume the first method call to be the task
        if (!$this->Name) {
            $this->Name = $method;
        }
        // If no owner is set, assume that the member is the owner
        if (!$this->OwnerID && $class instanceof Member) {
            $this->OwnerID = $class->ID;
        }

        // Task details contain one entry per thing to do in a task
        // A task can do multiple calls
        $details[] = [
            'class' => get_class($class),
            'id' => $class->ID,
            'function' => $method,
            'parameters' => $params
        ];

        $json = json_encode($details);
        if ($json) {
            $this->Task = $json;
        }
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
                /** @var class-string $class */
                $class = $entry['class'] ?? '';
                /** @var int $id */
                $id = $entry['id'];
                /** @var string $function */
                $function = $entry['function'];
                /** @var array<mixed> $parameters */
                $parameters = $entry['parameters'];

                $inst = DataObject::get_by_id($class, $id);

                $callable = [$inst, $function];
                if (!is_callable($callable)) {
                    throw new Exception("Not callable $function");
                }
                if ($inst) {
                    $result = call_user_func_array($callable, $parameters);
                    if ($result !== false) {
                        $success++;
                    } else {
                        $errors++;
                    }
                }
            }
            $this->Failed = false;
            $this->ErrorMessage = '';
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
