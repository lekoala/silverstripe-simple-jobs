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
use SilverStripe\CronTask\Interfaces\CronTask;

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

    public function testTriggerClearsItsLockAfterException(): void
    {
        $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'simple-jobs-' . bin2hex(random_bytes(8));
        $failingTask = new class (false) implements CronTask {
            public function __construct(bool $fail = true)
            {
                if ($fail) {
                    throw new \RuntimeException('Expected test exception');
                }
            }

            public function getSchedule()
            {
                return '* * * * *';
            }

            public function process()
            {
            }
        };
        $controller = new class ($lockFile, get_class($failingTask)) extends SimpleJobsController {
            /** @var string */
            private $testLockFile;

            /** @var class-string */
            private $testTaskClass;

            public function __construct(string $testLockFile, string $testTaskClass)
            {
                parent::__construct();
                $this->testLockFile = $testLockFile;
                $this->testTaskClass = $testTaskClass;
            }

            protected function getLockFile($type): string
            {
                return $this->testLockFile;
            }

            protected function getCronTasks(): array
            {
                return [$this->testTaskClass];
            }
        };
        $exceptionThrown = false;
        $lockWasRemoved = false;
        ob_start();
        try {
            $controller->trigger();
        } catch (\RuntimeException $exception) {
            $exceptionThrown = true;
            $this->assertSame('Expected test exception', $exception->getMessage());
        } finally {
            ob_end_clean();
            $lockWasRemoved = !is_file($lockFile);
            if (!$lockWasRemoved) {
                unlink($lockFile);
            }
        }

        $this->assertTrue($exceptionThrown);
        $this->assertTrue($lockWasRemoved);
    }
}
