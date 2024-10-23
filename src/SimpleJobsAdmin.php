<?php

namespace LeKoala\SimpleJobs;

use SilverStripe\ORM\DataList;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\Director;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;

/**
 * Show all jobs and their results
 */
class SimpleJobsAdmin extends ModelAdmin
{
    /**
     * @var array<class-string>
     */
    private static $managed_models = [
        CronJob::class,
        CronTaskResult::class,
        SimpleTask::class,
    ];

    /**
     * @var string
     */
    private static $url_segment = 'jobs';

    /**
     * @var string
     */
    private static $menu_title = 'Jobs';

    /**
     * @var string
     */
    private static $menu_icon_class = "font-icon-checklist";

    /**
     * @var boolean
     */
    public $showImportForm = false;

    /**
     * @var int
     */
    private static $page_length = 50;

    /**
     * @return DataList
     */
    public function getList()
    {
        if ($this->modelClass == CronJob::class) {
            CronJob::regenerateFromClasses(Director::isDev());
        }
        $list = parent::getList();
        return $list;
    }

    protected function getGridFieldConfig(): GridFieldConfig
    {
        $config = parent::getGridFieldConfig();
        $config->removeComponentsByType(GridFieldAddNewButton::class);
        return $config;
    }
}
