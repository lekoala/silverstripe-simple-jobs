<?php

namespace LeKoala\SimpleJobs;

use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\CronTask\Interfaces\CronTask;

/**
 * Class \LeKoala\SimpleJobs\CronJob
 *
 * @property string $TaskClass
 * @property string $Category
 * @property string $Description
 * @property bool $Disabled
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
        'TaskClass' => 'Task Class',
        'Category' => 'Category',
        'Description' => 'Description',
        'LastResult.Created' => 'Last Run'
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

    public static function regenerateFromClasses(bool $update = false): void
    {
        $list = self::allTasks();
        foreach ($list as $class) {
            $obj = self::get()->filter('TaskClass', $class)->first();
            if ($obj && $update === false) {
                continue;
            }
            if (!$obj) {
                $obj = new CronJob();
            }
            $obj->TaskClass = $class;
            $obj->Category = method_exists($class, 'getCategory') ? $class::getCategory() : 'general';
            $obj->Description = method_exists($class, 'getDescription') ? $class::getDescription() : '';
            $obj->write();
        }
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
