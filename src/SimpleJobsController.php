<?php

namespace LeKoala\SimpleJobs;

use DateTime;
use Exception;
use Cron\CronExpression;
use SilverStripe\ORM\DB;
use Psr\Log\LoggerInterface;
use LeKoala\SimpleJobs\CronJob;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Control\Controller;
use SilverStripe\Security\Permission;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\CronTask\CronTaskStatus;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\CronTask\Interfaces\CronTask;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;

/**
 * A controller that triggers the jobs from an http request
 *
 */
class SimpleJobsController extends Controller
{
    /**
     * @var string
     */
    private static $url_segment = 'simple-jobs';

    /**
     * @var array<string, string>
     */
    private static $url_handlers = [
        'simple-jobs/$Action/$ID/$OtherID' => 'handleAction',
    ];

    /**
     * @var array<string>
     */
    private static $allowed_actions = [
        'trigger',
        'trigger_manual',
        'trigger_next_task',
        'viewlogs',
    ];

    /**
     * @var bool
     */
    protected $basicAuthEnabled = false;

    /**
     * @var string
     */
    protected static $currentTask = null;

    /**
     * @return void
     */
    public function init()
    {
        // Avoid multiple auths
        if (self::config()->username) {
            $this->basicAuthEnabled = false;
        }

        parent::init();

        HTTPCacheControlMiddleware::singleton()->disableCache();
    }

    /**
     * @return string|HTTPResponse|void
     */
    public function index()
    {
        $this->basicAuth();

        if (!Director::isDev()) {
            return 'Listing tasks is only available in dev mode';
        }

        $tasks = CronJob::allTasks();
        if (empty($tasks)) {
            return "There are no implementators of CronTask to run";
        }

        $segment = self::$url_segment;

        foreach ($tasks as $task) {
            $taskName = $task;

            $job = CronJob::getByTaskClass($task);
            if ($job && $job->IsDisabled()) {
                $taskName .= ' - disabled';
            }

            $taskUrl = str_replace('\\', '-', $task);

            $link = "/$segment/trigger_manual/" . $taskUrl;
            $forceLink = "/$segment/trigger_manual/" . $taskUrl;
            $this->output('<a href="' . $link . '">' . $taskName . '</a>');
            $this->output('<a href="' . $forceLink . '?force=1">' . $taskName . ' (forced run)</a>');
        }

        $this->output('');
        $this->output('<a href="/' . $segment . '/trigger_next_task">Trigger next simple task</a>');

        if (self::config()->store_results) {
            $this->output('');
            $this->output('<a href="/' . $segment . '/viewlogs">View 10 most recent log entries</a>');
            $this->output('<a href="/' . $segment . '/viewlogs/100">View 100 most recent log entries</a>');
        }
    }

    /**
     * @return string|HTTPResponse|void
     */
    public function viewlogs()
    {
        if (!Director::isDev() && !Permission::check('ADMIN')) {
            return "View logs is only available in dev mode or for admins";
        }

        $limit = (int) $this->getRequest()->param('ID');
        if (!$limit) {
            $limit = 10;
        }

        $results = CronTaskResult::get()->limit($limit);

        if (!$results->count()) {
            $this->output("No results to display");
        } else {
            $this->output("Displaying last $limit results");
        }

        foreach ($results as $result) {
            $this->output($result->Status());
            $this->output($result->PrettyResult());
        }
    }

    /**
     * This is a dedicated endpoint to manually run a specific job for admin
     *
     * @return string|void
     */
    public function trigger_manual()
    {
        if (!Permission::check('ADMIN')) {
            return 'You must be logged as an admin';
        }

        $class = $this->getRequest()->param('ID');
        if (!$class) {
            return 'You must specify a class';
        }
        $class = str_replace('-', '\\', $class);
        if (!class_exists($class)) {
            return 'Invalid class name';
        }

        $forceRun = $this->getRequest()->getVar('force') ? true : false;

        $cronJob = CronJob::getByTaskClass($class);
        if ($cronJob && $cronJob->IsDisabled() && !$forceRun) {
            return 'Task is disabled, use forced run';
        }

        /** @var \SilverStripe\CronTask\Interfaces\CronTask $task */
        $task = new $class();
        $this->runTask($task, $forceRun);
    }

    /**
     * This is a dedicated endpoint to force run the next task
     *
     * @return string|void
     */
    public function trigger_next_task()
    {
        if (!Director::isDev() && !Permission::check('ADMIN')) {
            return 'You must be logged as an admin or in dev mode';
        }

        $simpleTask = SimpleTask::getNextTaskToRun();
        if ($simpleTask) {
            $failed = $simpleTask->process();
            $message = 'ok';
            if ($failed) {
                $message = $simpleTask->ErrorMessage ? $simpleTask->ErrorMessage : 'not ok';
            }
            return $message;
        }

        $c = SimpleTask::get()->filter('Processed', 0)->count();
        $t = date('Y-m-d H:i:s');
        return 'No task (' . $c . ' future tasks, current time is ' . $t . ')';
    }

    /**
     * This is the endpoint that must be called by your monitoring system
     * You can create two endpoints:
     * - one with /trigger/cron for jobs
     * - one with /trigger/task for tasks
     *
     * If unspecified, it will run all jobs and the next task
     *
     * @return void
     */
    public function trigger()
    {
        // Never set a limit longer than the frequency at which this endpoint is called
        Environment::increaseTimeLimitTo(self::config()->time_limit);

        $this->keyAuth();
        $this->basicAuth();

        // We can set a type (cron|task). If empty, we run both cron and task
        $type = $this->getRequest()->param("ID");
        if ($type && !in_array($type, ['cron', 'task'])) {
            throw new Exception("Only 'cron' and 'task' are valid parameters");
        }

        // Create the lock file
        $lockFile = Director::baseFolder() . "/.simple-jobs-lock";
        if ($type) {
            $lockFile .= "-" . $type;
        }
        $now = date('Y-m-d H:i:s');
        if (is_file($lockFile)) {
            $lock_file_warn_early = self::config()->lock_file_warn_early;
            $t = file_get_contents($lockFile);
            $ip = $this->getRequest()->getIP();

            // there is an uncleared lockfile ?
            if ($lock_file_warn_early) {
                $this->getLogger()->error("Uncleared lock file created at $t ($type) - $ip");
            }

            // prevent running tasks < 5 min
            $nowt = strtotime($now);
            if ($t && $nowt) {
                $nowMinusFive = strtotime("-5 minutes", $nowt);
                if (strtotime($t) > $nowMinusFive) {
                    $this->output("Prevent running concurrent queues");
                    return;
                }
            }

            if (!$lock_file_warn_early) {
                $this->getLogger()->error("Uncleared lock file created at $t ($type) - $ip");
            }

            // clear anyway
            unlink($lockFile);
        }
        file_put_contents($lockFile, $now);

        $tasks = CronJob::allTasks();
        if (empty($tasks)) {
            $this->output("There are no implementators of CronTask to run");
            return;
        }

        // Do we have a cron job to run ?
        if (!$type || $type == "cron") {
            foreach ($tasks as $subclass) {
                $cronJob = CronJob::getByTaskClass($subclass);
                if ($cronJob && $cronJob->IsDisabled()) {
                    $this->output("Task $subclass is disabled");
                    continue;
                }
                /** @var \SilverStripe\CronTask\Interfaces\CronTask $task */
                $task = new $subclass();
                $this->runTask($task);
            }

            if (empty($tasks)) {
                $this->output("No jobs");
            }

            // Avoid the table to be full of stuff
            if (self::config()->auto_clean) {
                if (self::config()->store_results) {
                    self::clearResultsTable();
                }
            }
        }

        // Do we have a simple task to run ?
        if (!$type || $type == "task") {
            $simpleTask = SimpleTask::getNextTaskToRun();
            if ($simpleTask) {
                $simpleTask->process();
                $this->output("Processed task {$simpleTask->ID}");
            } else {
                $this->output("No task");
            }

            // Avoid the table to be full of stuff
            if (self::config()->auto_clean) {
                self::clearTasksTable();
            }
        }

        // Clear lock file (check if it hasn't be cleared in the meantime)
        if (is_file($lockFile)) {
            unlink($lockFile);
        }
    }

    /**
     * @return void
     */
    public static function clearTasksTable()
    {
        if (!self::config()->auto_clean_threshold) {
            return;
        }
        $time = date('Y-m-d', strtotime(self::config()->auto_clean_threshold));
        $sql = "DELETE FROM \"SimpleTask\" WHERE \"Created\" < '$time'";
        DB::query($sql);
    }

    /**
     * @return void
     */
    public static function clearResultsTable()
    {
        if (!self::config()->auto_clean_threshold) {
            return;
        }
        $time = date('Y-m-d', strtotime(self::config()->auto_clean_threshold));
        $sql = "DELETE FROM \"CronTaskResult\" WHERE \"Created\" < '$time'";
        DB::query($sql);
    }

    /**
     * Determine if a task should be run
     *
     * @param CronTask $task
     * @param \Cron\CronExpression $cron
     * @return bool
     */
    protected function isTaskDue(CronTask $task, \Cron\CronExpression $cron)
    {
        // Get last run status
        /** @var CronTaskStatus|null $status */
        $status = CronTaskStatus::get_status(get_class($task));

        // If the cron is due immediately, then run it
        $now = new DateTime(DBDatetime::now()->getValue());
        if ($cron->isDue($now)) {
            if (empty($status) || empty($status->LastRun)) {
                return true;
            }
            // In case this process is invoked twice in one minute, supress subsequent executions
            $lastRun = new DateTime($status->LastRun);
            return $lastRun->format('Y-m-d H:i') != $now->format('Y-m-d H:i');
        }

        // If this is the first time this task is ever checked, no way to detect postponed execution
        if (empty($status) || empty($status->LastChecked)) {
            return false;
        }

        // Determine if we have passed the last expected run time
        $nextExpectedDate = $cron->getNextRunDate($status->LastChecked);
        return $nextExpectedDate <= $now;
    }

    /**
     * Checks and runs a single CronTask
     *
     * @param CronTask $task
     * @param boolean $forceRun
     * @return void
     */
    protected function runTask(CronTask $task, $forceRun = false)
    {
        $cron = new CronExpression($task->getSchedule());
        $isDue = $this->isTaskDue($task, $cron);
        $willRun = $isDue || $forceRun;
        // Update status of this task prior to execution in case of interruption
        CronTaskStatus::update_status(get_class($task), $willRun);
        if ($isDue || $forceRun) {
            $msg = ' will start now';
            if (!$isDue && $forceRun) {
                $msg .= " (forced run)";
            }
            $this->output(get_class($task) . $msg);

            $startDate = date('Y-m-d H:i:s');

            // Handle exceptions for tasks
            $error = null;
            try {
                self::$currentTask = get_class($task);
                // We override docblock return type because we allow a result
                /** @var mixed $result */
                $result = $task->process();
                $this->output(CronTaskResult::PrettifyResult($result));
            } catch (Exception $ex) {
                $result = false;
                $error = $ex->getMessage();
                $this->output(CronTaskResult::PrettifyResult($result));
            }

            $endDate = date('Y-m-d H:i:s');
            $timeToExecute = strtotime($endDate) - strtotime($startDate);

            // Store result if we return something
            if (self::config()->store_results && $result !== null) {
                $cronResult = new CronTaskResult;
                if ($result === false) {
                    $cronResult->Failed = true;
                    $cronResult->Result = $error;
                } else {
                    if (is_object($result)) {
                        $result = print_r($result, true);
                    } elseif (is_array($result)) {
                        $json = json_encode($result);
                        if ($json) {
                            $result = $json;
                        } else {
                            $result = json_last_error_msg();
                        }
                    }
                    $cronResult->Result = $result;
                }
                $cronResult->TaskClass = get_class($task);
                $cronResult->ForcedRun = $forceRun;
                $cronResult->StartDate = $startDate;
                $cronResult->EndDate = $endDate;
                $cronResult->TimeToExecute = $timeToExecute;
                $cronResult->write();
            }
        } else {
            $this->output(get_class($task) . ' will run at ' . $cron->getNextRunDate()->format('Y-m-d H:i:s') . '.');
        }

        self::$currentTask = null;
    }

    /**
     * @return ?string
     */
    public static function getCurrentTask()
    {
        return self::$currentTask;
    }

    /**
     * @param string|null $message
     * @param boolean $escape
     * @return void
     */
    protected function output($message, $escape = false)
    {
        if ($escape) {
            $message = htmlspecialchars($message ?? '', ENT_QUOTES, 'UTF-8');
        }
        echo $message . '<br />' . PHP_EOL;
    }

    protected function keyAuth()
    {
        $envKey = Environment::getEnv('SIMPLE_JOBS_KEY');
        if (!$envKey) {
            return true;
        }
        $key = $this->getRequest()->getVar('key');
        if (!$key) {
            $key = $this->getRequest()->getHeader('X-KEY');
        }
        if ($key != $envKey) {
            die("Invalid key");
        }
        return true;
    }

    /**
     * Enable BasicAuth in a similar fashion as BasicAuth class
     *
     * @return boolean
     * @throws HTTPResponse_Exception
     */
    protected function basicAuth()
    {
        if (Director::is_cli()) {
            return true;
        }

        $username = self::config()->username;
        $password = self::config()->password;
        if (!$username || !$password) {
            return true;
        }

        $authHeader = null;
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $matches = array();

        $hasBasicHeaders =  $authHeader && preg_match('/Basic\s+(.*)$/i', $authHeader, $matches);
        if ($hasBasicHeaders) {
            list($name, $password) = explode(':', base64_decode($matches[1]));
            $_SERVER['PHP_AUTH_USER'] = strip_tags($name);
            $_SERVER['PHP_AUTH_PW'] = strip_tags($password);
        }

        $authSuccess = false;
        if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
            if ($_SERVER['PHP_AUTH_USER'] == $username && $_SERVER['PHP_AUTH_PW'] == $password) {
                $authSuccess = true;
            }
        }

        if (!$authSuccess) {
            $realm = "Enter your credentials";
            $response = new HTTPResponse(null, 401);
            $response->addHeader('WWW-Authenticate', "Basic realm=\"$realm\"");

            if (isset($_SERVER['PHP_AUTH_USER'])) {
                $response->setBody(_t('BasicAuth.ERRORNOTREC', "That username / password isn't recognised"));
            } else {
                $response->setBody(_t('BasicAuth.ENTERINFO', "Please enter a username and password."));
            }

            // Exception is caught by RequestHandler->handleRequest() and will halt further execution
            $e = new HTTPResponse_Exception(null, 401);
            $e->setResponse($response);
            throw $e;
        }

        return $authSuccess;
    }

    /**
     * @return LoggerInterface
     */
    public static function getLogger()
    {
        return Injector::inst()->get(LoggerInterface::class)->withName('SimpleJobsController');
    }
}
