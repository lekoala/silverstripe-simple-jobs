<?php

/**
 * Store the result of a cron task
 *
 * @author Koala
 */
class CronTaskResult extends DataObject
{
    private static $db = array(
        'TaskClass' => 'Varchar(255)',
        'Result' => 'Text',
        'Failed' => 'Boolean',
    );

}