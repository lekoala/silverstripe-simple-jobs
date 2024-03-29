# SilverStripe Simple Jobs

![Build Status](https://github.com/lekoala/silverstripe-simple-jobs/actions/workflows/ci.yml/badge.svg)
[![scrutinizer](https://scrutinizer-ci.com/g/lekoala/silverstripe-simple-jobs/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/lekoala/silverstripe-simple-jobs/)
[![Code coverage](https://codecov.io/gh/lekoala/silverstripe-simple-jobs/branch/master/graph/badge.svg)](https://codecov.io/gh/lekoala/silverstripe-simple-jobs)

An alternative to run cron jobs that uses simple HTTP requests.

This module require [SilverStripe CronTask](https://github.com/silverstripe-labs/silverstripe-crontask).

# Why?

Configuring cron jobs is painful. Maybe you don't have proper access to your server,
or maybe it's difficult to make sure that php is run under the right user.
This is why you can simply rely on an external service to trigger a HTTP request
to a given script that will take care of any task you have to run.

# How to setup

## Using an external service

Simply call every 5 minutes the following url : yoursite.com/simple-jobs/trigger

In order to do that, you can use for example [UptimeRobot](https://uptimerobot.com/).
As an added bonus, will you monitor if your webserver is responding which is always nice to have :-).

## Using your own requests

Don't like using a service like UptimeRobot ? Feel free to setup your own http requets using
[Windows Scheduled Tasks](https://learn.microsoft.com/en-us/previous-versions/windows/it-pro/windows-server-2008-R2-and-2008/cc748993(v=ws.11)) or another server using cron.

For instance

    * * * * * wget -O - http://yoursite.com/simple-jobs/trigger >/dev/null 2>&1

## Using regular cron jobs

You can also use this module similarly to the base crontask module, while getting
all the logging benefits.

Add the following command to your cron definition:

    * * * * * www-data /usr/bin/php /path/to/silverstripe/docroot/framework/cli-script.php simple-jobs/trigger

# Enabling BasicAuth

To prevent malicious users to hammer your website by calling the url, you can
enable BasicAuth. Define the following in your config file:

```yml
LeKoala\SimpleJobs\SimpleJobsController:
    username: "myusername"
    password: "mypassword"
```

And make sure that the proper headers are sent by UptimeRobot or any system you
use to trigger HTTP requests.

# Testing

You can also test your tasks by visiting yoursite.com/simple-jobs/.

This is enabled only if Dev mode is active. You can click on any task in the list,
and choose to run it according to its schedule or force the task to run.

If needed, you can also trigger manually your jobs. Simply visit /simple-jobs/trigger_manual/YourClass.

# Log results

As an added bonus, this module will by default log all results returned by the
process() method. It will be stored in the database for further analysis.

Any task returning "false" will be marked as Failed.

Any task that triggers an exception will be marked as Failed and the Exception will be stored as the result.
This has also the side benefit of allowing other tasks to be run even if one of them raise an exception.

# Predefined schedules

If you don't like the cron syntax, you can also use any constant from the SimpleJobSchedules class, that
provides sane defaults for most common schedules.

```php
class TestCron implements CronTask {

    /**
    * run this task every every day
    *
    * @return string
    */
    public function getSchedule() {
        return SimpleJobsSchedules::EVERY_DAY;
    }

    /**
    *
    * @return void
    */
    public function process() {
        echo 'hello';
    }
}
```

It also comes with two methods: everyDay and everyWeek, that allow you to define the task
to run on a specific hour or day in the week.
This is useful if you have multiple daily or weekly task and that you don't want to run
them at the same time (because it could cause a timeout).

# Simple Tasks

This module also has a limited job runner feature in form of the `SimpleTask` class. A simple task
is simply a delayed call to a given method on a given `DataObject`. You can pass any number of
json serializable parameters (not object instances, for example).

```php
$task = new SimpleTask();

$task->addToTask($this, "removeStuff", [
    $some_stuff_id,
]);
```

One simple task can have many calls to any number of methods on any number of object instances.
Keep in mind that the `SimpleJobsController` will only run one task each time (every 5 minutes
or less depending on how you configured the module), so you may want to group in one
simple task everything that is related to one specific process.

# Cleaning sessions

Since we have the session-manager, we need to [clean sessions](https://github.com/silverstripe/silverstripe-session-manager#garbage-collection).

This module does it for you automatically daily. This can be disabled:

```yml
LeKoala\SimpleJobs\SimpleJobsDailyTask:
    clean_sessions: false
```

# Compatibility

Tested with 5+

# Maintainer

LeKoala - thomas@lekoala.be
