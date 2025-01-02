<?php

namespace LeKoala\SimpleJobs\Test;

use LeKoala\SimpleJobs\CronJob;
use SilverStripe\Security\Member;
use LeKoala\SimpleJobs\SimpleTask;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Security;
use SilverStripe\Control\Controller;
use LeKoala\SimpleJobs\SimpleJobsController;
use SilverStripe\ORM\DB;
use SilverStripe\Security\DefaultAdminService;

/**
 * Test for SimpleJobs
 *
 * @group SimpleJobs
 */
class SimpleJobsTest extends SapphireTest
{
    /**
     * Defines the fixture file to use for this test class
     * @var string
     */
    protected static $fixture_file = 'SimpleJobsTest.yml';

    protected function setUp(): void
    {
        parent::setUp();
        $controller = Controller::curr();
        $controller->config()->set('url_segment', 'test_controller');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testSimpleTask(): void
    {
        $task = new SimpleTask;
        $inst = Member::get()->first();
        $curr = $inst->TempIDHash;
        $task->addToTask($inst, 'regenerateTempID');
        $task->write();

        // Make sure it's marked as being the next one to process
        $next = SimpleTask::getNextTaskToRun();
        $this->assertEquals($task->ID, $next->ID);
        $count = SimpleTask::getTasksThatNeedToRun()->count();
        $this->assertEquals(1, $count);

        // It's still the same
        $this->assertEquals($curr, $inst->TempIDHash);
        $task->process();

        // refresh inst
        $inst = Member::get()->first();
        $this->assertNotEquals($curr, $inst->TempIDHash);

        $this->assertEquals(true, $task->Processed);
        $this->assertEquals(1, $task->SuccessCalls);
        $this->assertEquals(1, $task->CallsCount);
        $this->assertEquals(0, $task->ErrorCalls);

        $count = SimpleTask::getTasksThatNeedToRun()->count();
        $this->assertEquals(0, $count);
    }

    public function testController(): void
    {
        $ctrl = new SimpleJobsController();

        Security::setCurrentUser(null);
        $res = $ctrl->trigger_manual();
        $this->assertStringContainsString("must be logged", $res);

        $service = DefaultAdminService::singleton();
        $admin = $service->findOrCreateDefaultAdmin();
        Security::setCurrentUser($admin);

        $res = $ctrl->trigger_manual();
        $this->assertStringNotContainsString("must be logged", $res);
    }

    public function testCanGenerateJobs(): void
    {
        CronJob::regenerateFromClasses();
        $this->assertNotEquals(0, CronJob::get()->count());
    }

    public function testHasTasks(): void
    {
        $this->assertNotEmpty(CronJob::allTasks());
    }

    public function testClearResults(): void
    {
        $t = date('Y-m-d', strtotime('-1 year'));
        DB::query("INSERT INTO CronTaskResult (Created) VALUES ('$t')");

        $count = DB::query('SELECT COUNT(*) FROM CronTaskResult')->value();

        SimpleJobsController::clearResultsTable();

        $newCount = DB::query('SELECT COUNT(*) FROM CronTaskResult')->value();
        $this->assertNotEquals($count, $newCount);
    }
}
