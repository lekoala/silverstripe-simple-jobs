<?php

/**
 * A controller that triggers the jobs from an http request
 *
 * @author Koala
 */
class SimpleJobsController extends Controller
{
    private static $url_handlers = array(
        'simple-jobs/$Action/$ID/$OtherID' => 'handleAction',
    );
    private static $allowed_actions = array(
        'trigger',
        'trigger_manual',
        'viewlogs',
    );

    public function init()
    {
        // Avoid multiple auths
        if (self::config()->username) {
            $this->basicAuthEnabled = false;
        }

        parent::init();
    }

    public function index()
    {
        $this->basicAuth();

        if (!Director::isDev()) {
            return 'Listing tasks is only available in dev mode';
        }


        $tasks = $this->allTasks();
        if (empty($tasks)) {
            return "There are no implementators of CronTask to run";
        }

        $subclass = $this->getRequest()->param('ID');
        if ($subclass && in_array($subclass, $tasks)) {
            $forceRun = $this->getRequest()->getVar('force');
            $task = new $subclass();
            $this->runTask($task, $forceRun);
            return;
        }

        $disabled = self::config()->disabled_tasks;
        foreach ($tasks as $task) {
            $taskName = $task;
            if ($disabled && in_array($taskName, $disabled)) {
                $taskName .= ' - disabled';
            }
            $link = "/simple-jobs/index/" . $task;
            $this->output('<a href="' . $link . '">' . $taskName . '</a>');
            $this->output('<a href="' . $link . '?force=1">' . $taskName . ' (forced run)</a>');
        }

        if (self::config()->store_results) {
            $this->output('');
            $this->output('<a href="/simple-jobs/viewlogs">View 10 most recent log entries</a>');
            $this->output('<a href="/simple-jobs/viewlogs/100">View 100 most recent log entries</a>');
        }
    }

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

    public function trigger_manual()
    {
        if (!Permission::check('ADMIN')) {
            return 'You must be logged as an admin';
        }

        $class = $this->getRequest()->param('ID');
        if (!$class) {
            return 'You must specify a class';
        }
        if (!class_exists($class)) {
            return 'Invalid class name';
        }

        $task = new $class();
        $this->runTask($task, true);
    }

    public function trigger()
    {
        // Never set a limit longer than the frequency at which this endpoint is called
        increase_time_limit_to(self::config()->time_limit);

        $this->basicAuth();

        $tasks = ClassInfo::implementorsOf('CronTask');
        if (empty($tasks)) {
            return "There are no implementators of CronTask to run";
        }
        $disabled = self::config()->disabled_tasks;
        foreach ($tasks as $subclass) {
            if (Director::isLive() && in_array($subclass, $disabled)) {
                $this->output("Task $subclass is disabled");
                continue;
            }
            // Check if disabled
            $task = new $subclass();
            $this->runTask($task);
        }
    }

    /**
     * Determine if a task should be run
     *
     * @param CronTask $task
     * @param \Cron\CronExpression $cron
     */
    protected function isTaskDue(CronTask $task, \Cron\CronExpression $cron)
    {
        // Get last run status
        $status = CronTaskStatus::get_status(get_class($task));

        // If the cron is due immediately, then run it
        $now = new DateTime(SS_Datetime::now()->getValue());
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
     */
    protected function runTask(CronTask $task, $forceRun = false)
    {
        $cron = Cron\CronExpression::factory($task->getSchedule());
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
                        $result = json_encode($result);
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
    }

    protected function output($message, $escape = false)
    {
        if ($escape) {
            $message = Convert::raw2xml($message);
        }
        echo $message . '<br />' . PHP_EOL;
    }

    protected function allTasks()
    {
        return ClassInfo::implementorsOf('CronTask');
    }

    /**
     * Enable BasicAuth in a similar fashion as BasicAuth class
     *
     * @return boolean
     * @throws SS_HTTPResponse_Exception
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

        if ($authHeader &&
            preg_match('/Basic\s+(.*)$/i', $authHeader, $matches)) {
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
            $response = new SS_HTTPResponse(null, 401);
            $response->addHeader('WWW-Authenticate', "Basic realm=\"$realm\"");

            if (isset($_SERVER['PHP_AUTH_USER'])) {
                $response->setBody(_t('BasicAuth.ERRORNOTREC', "That username / password isn't recognised"));
            } else {
                $response->setBody(_t('BasicAuth.ENTERINFO', "Please enter a username and password."));
            }

            // Exception is caught by RequestHandler->handleRequest() and will halt further execution
            $e = new SS_HTTPResponse_Exception(null, 401);
            $e->setResponse($response);
            throw $e;
        }

        return $authSuccess;
    }
}
