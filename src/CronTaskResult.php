<?php

namespace LeKoala\SimpleJobs;

use SilverStripe\ORM\DataObject;

/**
 * Store the result of a cron task
 *
 * @property ?string $TaskClass
 * @property ?string $Result
 * @property bool|int $Failed
 * @property bool|int $ForcedRun
 * @property ?string $StartDate
 * @property ?string $EndDate
 * @property int $TimeToExecute
 * @mixin \SilverStripe\Assets\Shortcodes\FileLinkTracking
 * @mixin \SilverStripe\Assets\AssetControlExtension
 * @mixin \SilverStripe\CMS\Model\SiteTreeLinkTracking
 * @mixin \SilverStripe\Versioned\RecursivePublishable
 * @mixin \SilverStripe\Versioned\VersionedStateExtension
 */
class CronTaskResult extends DataObject
{
    /**
     * @var string
     */
    private static $singular_name = 'Job Result';

    /**
     * @var string
     */
    private static $plural_name = 'Job Results';

    /**
     * @var string
     */
    private static $table_name = 'CronTaskResult';

    /**
     * @var array<string, string>
     */
    private static $db = [
        'TaskClass' => 'Varchar(255)',
        'Result' => 'Text',
        'Failed' => 'Boolean',
        'ForcedRun' => 'Boolean',
        'StartDate' => 'Datetime',
        'EndDate' => 'Datetime',
        'TimeToExecute' => 'Int',
    ];

    /**
     * @var string
     */
    private static $default_sort = 'Created DESC';

    /**
     * @var array<string, string>
     */
    private static $summary_fields = [
        'Created' => 'Created',
        'TaskClass' => 'Task Class',
        'Failed' => 'Failed',
        'TimeToExecute' => 'Time To Execute'
    ];

    public function Status(): string
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

    public function PrettyResult(): string
    {
        return self::PrettifyResult($this->Result);
    }

    /**
     * @param string|object|array<mixed>|bool|null $result
     * @return string
     */
    public static function PrettifyResult($result): string
    {
        if ($result === false) {
            $result = 'Task failed';
        }
        if (is_object($result)) {
            $result = print_r($result, true);
        } elseif (is_array($result)) {
            $result = json_encode($result);
        } elseif ($result === null) {
            $result = 'NULL';
        }
        return '<pre> ' . $result . '</pre>';
    }
}
