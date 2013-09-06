<?php

namespace Alchemy\Test\TaskManager;

use Alchemy\TaskManager\AbstractJob;
use Alchemy\TaskManager\JobDataInterface;
use Alchemy\TaskManager\JobInterface;
use Alchemy\Test\TaskManager\PhpProcess;
use Symfony\Component\Finder\Finder;
use Alchemy\TaskManager\Event\TaskManagerEvents;

class AbstractJobTest extends \PHPUnit_Framework_TestCase
{
    private $lockDir;

    public function setUp()
    {
        $this->lockDir = __DIR__ . '/LockDir';
        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir);
        }

        $finder = Finder::create();
        $finder->useBestAdapter();

        $finder->files()->in($this->lockDir);

        foreach ($finder as $file) {
            unlink($file->getPathname());
        }
    }

    private function getSelfStoppingScript()
    {
        return '<?php
        require "'.__DIR__.'/../../../../vendor/autoload.php";

        use Alchemy\TaskManager\JobDataInterface;

        class Job extends Alchemy\TaskManager\AbstractJob
        {
            public function __construct()
            {
                parent::__construct();
                $this->setId("laal");
                $this->setLockDirectory("' . $this->lockDir . '");
            }

            protected function doRun(JobDataInterface $data = null)
            {
                $n = 0;
                declare(ticks=1);
                while ($n < 60 && $this->getStatus() === static::STATUS_STARTED) {
                    usleep(10000);
                    $n++;
                }
                $this->stop();
            }
        }

        $job = new Job();
        $job->run();
        ';
    }

    private function getNonStoppingScript($time, $extra, $conf)
    {
        return '<?php
        require "'.__DIR__.'/../../../../vendor/autoload.php";

        use Alchemy\TaskManager\JobDataInterface;

        class Job extends Alchemy\TaskManager\AbstractJob
        {
            private $data;

            public function __construct()
            {
                parent::__construct();
                $this->setId("laal");
                $this->setLockDirectory("' . $this->lockDir . '");
            }

            protected function doRun(JobDataInterface $data = null)
            {
            declare(ticks=1);
                '.$extra.'
                usleep('.($time*10).'*100000);
            }
        }

            declare(ticks=1);
        $job = new Job();
        '.$conf.'
        assert($job === $job->run());
        ';
    }

    public function testLockingShouldPreventRunningTheSameProcessWIthSameIdTwice()
    {
        $script = $this->getSelfStoppingScript();

        $process1 = new PhpProcess($script);
        $process2 = new PhpProcess($script);

        $process1->start();
        usleep(300000);
        $process2->run();
        $process1->wait();

        $this->assertTrue($process1->isSuccessful());
        $this->assertFalse($process2->isSuccessful());

        $this->assertEquals(0, $process1->getExitCode());
        $this->assertEquals(255, $process2->getExitCode());
    }

    public function testLockFilesAreRemovedOnStop()
    {
        $finder = Finder::create();
        $finder->useBestAdapter();

        $process1 = new PhpProcess($this->getSelfStoppingScript());
        $process1->start();

        usleep(100000);
        $finder->files()->in($this->lockDir);
        $this->assertCount(1, $finder);

        $process1->wait();

        $this->assertCount(0, $finder);
    }

    /**
     * @dataProvider provideSignals
     */
    public function testLockFilesAreRemovedOnStopSignal($signal)
    {
        $process1 = new PhpProcess($this->getSelfStoppingScript());
        $process1->start();

        usleep(100000);
        $process1->signal($signal);
        $start = microtime(true);
        $process1->wait();
        $this->assertLessThan(0.1, microtime(true) - $start);

        $finder = Finder::create();
        $finder->useBestAdapter();
        $finder->files()->in($this->lockDir);
        $this->assertCount(0, $finder);
    }

    public function provideSignals()
    {
        return array(
            array(SIGTERM),
            array(SIGINT),
        );
    }

    /**
     * @dataProvider provideVariousDurationValues
     */
    public function testMaxDuration($max)
    {
        $script = $this->getNonStoppingScript(0.1, '', '$job->enableStopMode(Alchemy\TaskManager\JobInterface::MODE_STOP_ON_DURATION);$job->setMaxDuration('.$max.');');
        $process1 = new PhpProcess($script);

        $start = microtime(true);
        $process1->run();

        $duration = microtime(true) - $start;

        $this->assertLessThan(0.2, abs($max-$duration));
    }

    public function provideVariousDurationValues()
    {
        return array(array(0.3), array(0.5), array(0.7));
    }

    /**
     * @dataProvider provideVariousPeriods
     */
    public function testPeriodicSignal($periodMilliseconds)
    {
        $script = $this->getNonStoppingScript(0.1, '', '$job->enableStopMode(Alchemy\TaskManager\JobInterface::MODE_STOP_UNLESS_SIGNAL);$job->setSignalPeriod('.($periodMilliseconds / 1000).');');

        $process1 = new PhpProcess($script);
        $process1->start();

        $end = microtime(true) + (7 * $periodMilliseconds / 1000);

        while (microtime(true) < $end) {
            usleep($periodMilliseconds * 1000 * 2 / 3);
            $process1->signal(SIGCONT);
            $this->assertTrue($process1->isRunning());
        }

        usleep($periodMilliseconds * 1000 * 3 / 2);
        $this->assertFalse($process1->isRunning());
    }

    public function provideVariousPeriods()
    {
        return array(
            array(150),
            array(450),
        );
    }

    private function getPauseScript()
    {
        return '<?php
        require "'.__DIR__.'/../../../../vendor/autoload.php";

        use Alchemy\TaskManager\JobDataInterface;

        class Job extends Alchemy\TaskManager\AbstractJob
        {
            private $data;

            public function __construct()
            {
                parent::__construct();
                $this->setId("laal");
                $this->setLockDirectory("' . $this->lockDir . '");
            }

            protected function doRun(JobDataInterface $data = null)
            {
            }

            protected function getPauseDuration()
            {
                return 2;
            }
        }

        $job = new Job();
        $job->run();
        ';
    }

    public function testStopWithinAPauseDoesNotWaitTheEndOfThePause()
    {
        $script = $this->getPauseScript();
        $process = new PhpProcess($script);

        $process->start();
        usleep(500000);
        $start = microtime(true);
        $process->stop();
        $this->assertFalse($process->isRunning());
        $this->assertLessThan(0.1, microtime(true) - $start);
    }

    private function getPauseAndLoopScript()
    {
        return '<?php
        require "'.__DIR__.'/../../../../vendor/autoload.php";

        use Alchemy\TaskManager\JobDataInterface;

        class Job extends Alchemy\TaskManager\AbstractJob
        {
            private $data;

            public function __construct()
            {
                parent::__construct();
                $this->setId("laal");
                $this->setLockDirectory("' . $this->lockDir . '");
            }

            protected function doRun(JobDataInterface $data = null)
            {
                echo "loop\n";
            }

            protected function getPauseDuration()
            {
                return 0.1;
            }
        }

        $job = new Job();
        $job->run();
        ';
    }

    private function getEventsScript()
    {
        return '<?php
        require "'.__DIR__.'/../../../../vendor/autoload.php";

        use Alchemy\TaskManager\JobDataInterface;
        use Alchemy\TaskManager\Event\TaskManagerEvents;

        class Job extends Alchemy\TaskManager\AbstractJob
        {
            private $data;

            public function __construct()
            {
                parent::__construct();
                $this->setId("laal");
                $this->setLockDirectory("' . $this->lockDir . '");
            }

            protected function doRun(JobDataInterface $data = null)
            {
            }

            protected function getPauseDuration()
            {
                return 0.1;
            }
        }

        $job = new Job();
        $job->addListener(TaskManagerEvents::START, function () { echo "start\n"; });
        $job->addListener(TaskManagerEvents::TICK, function () { echo "tick\n"; });
        $job->addListener(TaskManagerEvents::STOP, function () { echo "stop\n"; });
        $job->run();
        ';
    }

    public function testPauseDoesAPause()
    {
        $script = $this->getPauseAndLoopScript();
        $process = new PhpProcess($script);

        $process->start();
        usleep(550000);
        $process->stop();
        $this->assertFalse($process->isRunning());
        $loops = count(explode("loop\n", $process->getOutput()));
        $this->assertGreaterThanOrEqual(6, $loops);
        $this->assertLessThanOrEqual(7, $loops);
    }

    public function testEvents()
    {
        $script = $this->getEventsScript();
        $process = new PhpProcess($script);

        $process->start();
        usleep(550000);
        $process->stop();
        $this->assertFalse($process->isRunning());
        $data = array_filter(explode("\n", $process->getOutput()));
        $this->assertSame(TaskManagerEvents::START, $data[0]);
        $this->assertSame(TaskManagerEvents::TICK, $data[1]);
        $this->assertContains(TaskManagerEvents::STOP, $data);
    }

    public function testLockDirectoryGettersAndSetters()
    {
        $job = new JobTest();
        $this->assertSame(sys_get_temp_dir(), $job->getLockDirectory());
        $this->assertSame($job, $job->setLockDirectory(__DIR__));
        $this->assertSame(__DIR__, $job->getLockDirectory());
    }

    public function testLoggerGettersAndSetters()
    {
        $logger = $this->getMock('Psr\Log\LoggerInterface');

        $job = new JobTest();
        $this->assertSame(null, $job->getLogger());
        $this->assertSame($job, $job->setLogger($logger));
        $this->assertSame($logger, $job->getLogger());
    }

    public function testModeGettersAndSetters()
    {
        $job = new JobTest();
        $this->assertFalse($job->isStopMode(JobInterface::MODE_STOP_ON_DURATION));
        $this->assertFalse($job->isStopMode(JobInterface::MODE_STOP_UNLESS_SIGNAL));
        $job->enableStopMode(JobInterface::MODE_STOP_ON_DURATION);
        $this->assertTrue($job->isStopMode(JobInterface::MODE_STOP_ON_DURATION));
        $this->assertFalse($job->isStopMode(JobInterface::MODE_STOP_UNLESS_SIGNAL));
        $this->assertTrue($job->isStopMode(JobInterface::MODE_STOP_ON_DURATION));
        $this->assertFalse($job->isStopMode(JobInterface::MODE_STOP_UNLESS_SIGNAL));
        $job->enableStopMode(JobInterface::MODE_STOP_UNLESS_SIGNAL);
        $this->assertTrue($job->isStopMode(JobInterface::MODE_STOP_ON_DURATION));
        $this->assertTrue($job->isStopMode(JobInterface::MODE_STOP_UNLESS_SIGNAL));
        $this->assertTrue($job->isStopMode(JobInterface::MODE_STOP_ON_DURATION));
        $this->assertTrue($job->isStopMode(JobInterface::MODE_STOP_UNLESS_SIGNAL));
        $job->disableStopMode(JobInterface::MODE_STOP_ON_DURATION);
        $job->disableStopMode(JobInterface::MODE_STOP_UNLESS_SIGNAL);
        $this->assertFalse($job->isStopMode(JobInterface::MODE_STOP_ON_DURATION));
        $this->assertFalse($job->isStopMode(JobInterface::MODE_STOP_UNLESS_SIGNAL));
    }

    /**
     * @expectedException Alchemy\TaskManager\Exception\InvalidArgumentException
     * @expectedExceptionMessage Invalid mode value.
     */
    public function testEnableInvalidMode()
    {
        $job = new JobTest();
        $job->enableStopMode('invalid');
    }

    /**
     * @expectedException Alchemy\TaskManager\Exception\InvalidArgumentException
     * @expectedExceptionMessage Invalid mode value.
     */
    public function testDisableInvalidMode()
    {
        $job = new JobTest();
        $job->disableStopMode('invalid');
    }

    /**
     * @expectedException Alchemy\TaskManager\Exception\InvalidArgumentException
     * @expectedExceptionMessage Invalid mode value.
     */
    public function testTestInvalidMode()
    {
        $job = new JobTest();
        $job->isStopMode('invalid');
    }

    public function testMaxDurationGettersAndSetters()
    {
        $job = new JobTest();
        $this->assertSame(0, $job->getMaxDuration());
        $this->assertSame($job, $job->setMaxDuration(24));
        $this->assertSame((float) 24, $job->getMaxDuration());
    }

    /**
     * @dataProvider provideInvalidDurationValues
     * @expectedException Alchemy\TaskManager\Exception\InvalidArgumentException
     * @expectedExceptionMessage Maximum duration should be a positive value.
     */
    public function testInvalidMaxDurationValues($duration)
    {
        $job = new JobTest();
        $job->setMaxDuration($duration);
    }

    public function provideInvalidDurationValues()
    {
        return array(array(0), array(-20));
    }

    public function testSignalPeriodGettersAndSetters()
    {
        $job = new JobTest();
        $this->assertSame(0.5, $job->getSignalPeriod());
        $this->assertSame($job, $job->setSignalPeriod(42));
        $this->assertSame((float) 42, $job->getSignalPeriod());
    }

    /**
     * @dataProvider provideInvalidSignalPeriodValues
     * @expectedException Alchemy\TaskManager\Exception\InvalidArgumentException
     * @expectedExceptionMessage Signal period should be greater than 0.15 s.
     */
    public function testInvalidSignalPeriod($period)
    {
        $job = new JobTest();
        $job->setSignalPeriod($period);
    }

    public function provideInvalidSignalPeriodValues()
    {
        return array(array(0), array(-2), array(0.14));
    }

    public function testDataIsPassedToDoRun()
    {
        $data = $this->getMock('Alchemy\TaskManager\JobDataInterface');

        $job = new JobTest();
        $job->setId('Id');
        $job->enableStopMode(JobTest::MODE_STOP_ON_DURATION);
        $job->setMaxDuration(0.1);
        $job->run($data);

        $this->assertSame($data, $job->getData());
    }

    public function testSingleRunRunsAndStop()
    {
        $data = $this->getMock('Alchemy\TaskManager\JobDataInterface');

        $job = new JobTest();
        $job->setId('Id');
        $start = microtime(true);
        $this->assertSame($job, $job->singleRun($data));

        $this->assertLessThan(0.1, microtime(true) - $start);
        $this->assertSame($data, $job->getData());
    }

    public function testAddAListener()
    {
        $dispatcher = $this->getMock('Symfony\Component\EventDispatcher\EventDispatcherInterface');
        $listener = array($this, 'testAddAListener');
        $name = 'event-name';

        $dispatcher->expects($this->once())
                ->method('addListener')
                ->with($name, $listener);

        $job = new JobTest($dispatcher);
        $this->assertSame($job, $job->addListener($name, $listener));
    }

    public function testAddASubscriber()
    {
        $dispatcher = $this->getMock('Symfony\Component\EventDispatcher\EventDispatcherInterface');
        $subscriber = $this->getMock('Symfony\Component\EventDispatcher\EventSubscriberInterface');

        $dispatcher->expects($this->once())
                ->method('addSubscriber')
                ->with($subscriber);

        $job = new JobTest($dispatcher);
        $this->assertSame($job, $job->addSubscriber($subscriber));
    }

    public function testEventsAreDispatchedOnSingleRun()
    {
        $data = $this->getMock('Alchemy\TaskManager\JobDataInterface');

        $job = new JobTest();
        $job->setId('Id');
        $collector = array();
        $job->addListener(TaskManagerEvents::START, function () use (&$collector) {
            $collector[] = TaskManagerEvents::START;
        });
        $job->addListener(TaskManagerEvents::TICK, function () use (&$collector) {
            $collector[] = TaskManagerEvents::TICK;
        });
        $job->addListener(TaskManagerEvents::STOP, function () use (&$collector) {
            $collector[] = TaskManagerEvents::STOP;
        });

        $this->assertSame($job, $job->singleRun($data));
        $this->assertSame(TaskManagerEvents::START, $collector[0]);
        $this->assertSame(TaskManagerEvents::TICK, $collector[1]);
        $this->assertContains(TaskManagerEvents::STOP, $collector);
    }

    public function testDoRunWithoutdataIsOk()
    {
        $job = new JobTest();
        $job->setId('Id');
        $job->enableStopMode(JobTest::MODE_STOP_ON_DURATION);
        $job->setMaxDuration(0.1);
        $job->run();
    }

    public function testThatSettingAStopParameterEnablesTheStopMode()
    {
        $job = new JobTest();
        $this->assertFalse($job->isStopMode(JobTest::MODE_STOP_ON_DURATION));
        $this->assertFalse($job->isStopMode(JobTest::MODE_STOP_UNLESS_SIGNAL));
        $job->setMaxDuration(3);
        $this->assertTrue($job->isStopMode(JobTest::MODE_STOP_ON_DURATION));
        $job->disableStopMode(JobTest::MODE_STOP_ON_DURATION);
        $job->setSignalPeriod(2);
        $this->assertTrue($job->isStopMode(JobTest::MODE_STOP_UNLESS_SIGNAL));
    }

    public function testJobCanBeRestartedAfterAFailure()
    {
        $job = new JobFailureTest();
        $job->setId('failure-id');
        try {
            $job->run();
            $this->fail('A JobFailureException should have been raised');
        } catch (JobFailureException $e) {

        }
        $job = new JobFailureTest();
        $job->setId('failure-id');
        $this->setExpectedException('Alchemy\Test\TaskManager\JobFailureException', 'Total failure.');
        $job->run();
    }
}

class JobTest extends AbstractJob
{
    private $data;

    public function getData()
    {
        return $this->data;
    }

    protected function doRun(JobDataInterface $data = null)
    {
        $this->data = $data;
    }
}

class JobFailureTest extends AbstractJob
{
    protected function doRun(JobDataInterface $data = null)
    {
        throw new JobFailureException('Total failure.');
    }
}

class JobFailureException extends \Exception
{
}
