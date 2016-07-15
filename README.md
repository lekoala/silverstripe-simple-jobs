SilverStripe Simple Jobs
==================
An alternative to run cron jobs that uses simple HTTP requests.

This module require [SilverStripe CronTask](https://github.com/silverstripe-labs/silverstripe-crontask).

Why?
==================

Configuring cron jobs is painful. Maybe you don't have proper access to your server,
or maybe it's difficult to make sure that php is run under the right user.
This is why you can simply rely on an external service to trigger a HTTP request
to a given script that will take care of any task you have to run.

How to setup
==================

Simply call every 5 minutes the following url : yoursite.com/simple-jobs/trigger

In order to do that, you can use for example [UptimeRobot](https://uptimerobot.com/).
As an added bonus, will you monitor if your webserver is responding which is always nice to have :-).

Enabling BasicAuth
==================

To prevent malicious users to hammer your website by calling the url, you can
enable BasicAuth. Define the following in your config file:

    SimpleJobsController:
      username: 'myusername'
      password: 'mypassword'
      
And make sure that the proper headers are sent by UptimeRobot or any system you
use to trigger HTTP requests.

Testing
==================

You can also test your tasks by visiting yoursite.com/simple-jobs/.

This is enabled only if Dev mode is active. You can click on any task in the list,
and choose to run it according to its schedule or force the task to run.

Log results
==================

As an added bonus, this module will be default log all results returned by the
process() method. It will be stored in the database for further analysis.

Any task returning "false" will be marked as Failed.

Any task that triggers an exception will be marked as Failed and the Exception will be stored as the result.
This has also the side benefit of allowing other tasks to be run even if one of them raise an exception.

Predefined schedules
==================

If you don't like the cron syntax, you can also use any constant from the SimpleJobSchedules class, that
provides sane defaults for most common schedules.

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

Compatibility
==================
Tested with 3.x

Maintainer
==================
LeKoala - thomas@lekoala.be