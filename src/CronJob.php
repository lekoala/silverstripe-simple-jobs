<?php

namespace LeKoala\SimpleJobs;

use Exception;
use Cron\CronExpression;
use LeKoala\CmsActions\CustomAction;
use SilverStripe\ORM\DataList;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\FieldList;
use SilverStripe\Security\Member;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\CronTask\Interfaces\CronTask;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Security\Permission;

/**
 * Class \LeKoala\SimpleJobs\CronJob
 *
 * @property ?string $TaskClass
 * @property ?string $Title
 * @property ?string $Category
 * @property ?string $Description
 * @property bool|int $Disabled
 * @mixin \LeKoala\Base\Extensions\BaseDataObjectExtension
 * @mixin \SilverStripe\Assets\Shortcodes\FileLinkTracking
 * @mixin \SilverStripe\Assets\AssetControlExtension
 * @mixin \SilverStripe\CMS\Model\SiteTreeLinkTracking
 * @mixin \SilverStripe\Versioned\RecursivePublishable
 * @mixin \SilverStripe\Versioned\VersionedStateExtension
 */
class CronJob extends DataObject
{
    /**
     * @var string
     */
    private static $singular_name = 'Scheduled Job';

    /**
     * @var string
     */
    private static $plural_name = 'Scheduled Jobs';

    /**
     * @var string
     */
    private static $table_name = 'CronJob';

    /**
     * @var array<string, string>
     */
    private static $db = [
        'TaskClass' => 'Varchar(255)',
        'Title' => 'Varchar(255)',
        'Category' => 'Varchar(255)',
        'Description' => 'Varchar(255)',
        'Disabled' => 'Boolean',
    ];

    /**
     * @var string
     */
    private static $default_sort = 'TaskClass ASC';

    /**
     * @var array<string, string>
     */
    private static $summary_fields = [
        'Title' => 'Title',
        'Category' => 'Category',
        'IsDisabled' => 'IsDisabled',
        'Description' => 'Description',
        'LastResult.Created' => 'Last Run',
        'NextRun' => 'Next Run',
    ];

    /**
     * @param Member $member
     * @return boolean
     */
    public function canDelete($member = null)
    {
        return false;
    }

    /**
     * @return array<class-string>
     */
    public static function allTasks(): array
    {
        return ClassInfo::implementorsOf(CronTask::class);
    }

    public function triggerManually(): string
    {
        $inst = $this->TaskInstance();
        /** @var void|null|string|bool $result */
        $result = $inst->process();
        if ($result !== null) {
            if ($result === false) {
                return 'Task failed';
            }
            if (is_string($result)) {
                return $result;
            }
        }
        return "Task has been triggered";
    }

    public function getCMSActions()
    {
        $actions = parent::getCMSActions();
        if (class_exists(CustomAction::class) && Permission::check('ADMIN')) {
            $triggerManually = new CustomAction('triggerManually', 'Trigger manually');
            $triggerManually->setConfirmation("Are you sure you want to trigger the task?");
            $actions->push($triggerManually);
        }
        return $actions;
    }

    /**
     * @return FieldList
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->makeFieldReadonly('Title');
        $fields->makeFieldReadonly('Category');
        $fields->makeFieldReadonly('Description');
        $fields->makeFieldReadonly('TaskClass');
        if ($this->IsSystemDisabled()) {
            $fields->makeFieldReadonly('Disabled');
        }

        // Display results
        $resultsGridConfig = GridFieldConfig_RecordViewer::create();
        $resultsGrid = new GridField('Results', 'Results', $this->AllResults(), $resultsGridConfig);
        $fields->addFieldToTab('Root.Results', $resultsGrid);

        // Optionally provide a list of to be affected items
        $taskClass = $this->TaskClass;
        if (method_exists($taskClass, 'getJobRecords')) {
            $records = $taskClass::getJobRecords();
            if ($records) {
                $recordsGridConfig = GridFieldConfig_RecordViewer::create();
                $recordsGrid = new GridField('Records', 'Records', $records, $recordsGridConfig);
                $fields->addFieldToTab('Root.Records', $recordsGrid);
            }
        }

        return $fields;
    }

    public static function regenerateFromClasses(bool $update = false): void
    {
        $list = self::allTasks();
        foreach ($list as $class) {
            $obj = self::getByTaskClass($class);
            if ($obj && $update === false) {
                continue;
            }
            if (!$obj) {
                $obj = new CronJob();
            }
            $obj->TaskClass = $class;
            $obj->Title =  method_exists($class, 'getJobTitle') ? $class::getJobTitle() : $class;
            $obj->Category = method_exists($class, 'getJobCategory') ? $class::getJobCategory() : 'general';
            $obj->Description = method_exists($class, 'getJobDescription') ? $class::getJobDescription() : '';
            $obj->write();
        }
    }

    public static function getByTaskClass(string $class): ?CronJob
    {
        /** @var CronJob|null $obj */
        $obj = self::get()->filter('TaskClass', $class)->first();
        return $obj;
    }

    public function IsDisabled(): bool
    {
        if ($this->IsSystemDisabled()) {
            return true;
        }
        return $this->Disabled;
    }

    public function IsTaskDisabled(): bool
    {
        $taskClass = $this->TaskClass;
        if ($taskClass && method_exists($taskClass, 'IsDisabled')) {
            return $taskClass::IsDisabled();
        }
        return false;
    }

    public function IsSystemDisabled(): bool
    {
        if ($this->IsTaskDisabled()) {
            return true;
        }
        $disabledTask = Config::inst()->get(SimpleJobsController::class, 'disabled_tasks');
        if (in_array($this->TaskClass, $disabledTask)) {
            return true;
        }
        return false;
    }

    /**
     * @return CronTask
     */
    public function TaskInstance(): CronTask
    {
        /** @var class-string $class */
        $class = $this->TaskClass;
        $inst = new $class;
        if ($inst instanceof CronTask) {
            return $inst;
        }
        throw new Exception("Invalid class $class");
    }

    public function NextRun(): string
    {
        if ($this->IsDisabled()) {
            return '';
        }
        $task = $this->TaskInstance();
        $cron = new CronExpression($task->getSchedule());
        return $cron->getNextRunDate()->format('Y-m-d H:i:s');
    }

    /**
     * @return DataList
     */
    public function AllResults()
    {
        $result = CronTaskResult::get()->filter([
            'TaskClass' => $this->TaskClass
        ])->Sort('Created DESC');
        return $result;
    }

    public function LastResult(): ?CronTaskResult
    {
        /** @var CronTaskResult|null $result */
        $result = CronTaskResult::get()->filter([
            'TaskClass' => $this->TaskClass
        ])->Sort('Created DESC')->first();
        return $result;
    }

    public function LastFailedResult(): ?CronTaskResult
    {
        /** @var CronTaskResult|null $result */
        $result = CronTaskResult::get()->filter([
            'TaskClass' => $this->TaskClass,
            'Failed' => 1
        ])->Sort('Created DESC')->first();
        return $result;
    }
}
