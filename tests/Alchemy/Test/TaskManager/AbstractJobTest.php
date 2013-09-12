<?php

namespace Alchemy\Test\TaskManager;

use Alchemy\TaskManager\AbstractJob;
use Alchemy\TaskManager\JobDataInterface;
use Alchemy\Test\TaskManager\PhpProcess;
use Alchemy\TaskManager\Event\TaskManagerEvents;
use Alchemy\TaskManager\Event\Subscriber\DurationLimitSubscriber;
use Symfony\Component\Finder\Finder;
use Symfony\Component\EventDispatcher\Event;

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

    private function getPauseScript()
    {
        return '<?php
        require "'.__DIR__.'/../../../../vendor/autoload.php";

        use Alchemy\TaskManager\JobDataInterface;

        class Job extends Alchemy\TaskManager\AbstractJob
        {
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

    public function testCustomEventsAreWelcomed()
    {
        $saidCoucou = false;
        $job = new JobTestWithCustomEvent();
        $job->addListener('coucou', function () use (&$saidCoucou) { $saidCoucou = true; });
        $job->singleRun();
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

    private function getEventsScript($throwException)
    {
        return '<?php
        require "'.__DIR__.'/../../../../vendor/autoload.php";

        use Alchemy\TaskManager\JobDataInterface;
        use Alchemy\TaskManager\Event\TaskManagerEvents;

        class Job extends Alchemy\TaskManager\AbstractJob
        {
            protected function doRun(JobDataInterface $data = null)
            {
                '.($throwException ? 'throw new \Exception("failure");' : '').'
            }

            protected function getPauseDuration()
            {
                return 0.1;
            }
        }

        $job = new Job();
        $job->addSubscriber(new Alchemy\TaskManager\Event\Subscriber\StopSignalSubscriber(Neutron\SignalHandler\SignalHandler::getInstance()));
        $job->addListener(TaskManagerEvents::START, function () { echo "start\n"; });
        $job->addListener(TaskManagerEvents::TICK, function () { echo "tick\n"; });
        $job->addListener(TaskManagerEvents::STOP, function () { echo "stop\n"; });
        $job->addListener(TaskManagerEvents::EXCEPTION, function () { echo "exception\n"; });
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
        $script = $this->getEventsScript(false);
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

    public function testEventsWithException()
    {
        $script = $this->getEventsScript(true);
        $process = new PhpProcess($script);

        $process->start();
        usleep(550000);
        $process->stop();
        $this->assertFalse($process->isRunning());
        $data = array_filter(explode("\n", $process->getOutput()));
        $this->assertSame(TaskManagerEvents::START, $data[0]);
        $this->assertSame(TaskManagerEvents::TICK, $data[1]);
        $this->assertContains(TaskManagerEvents::EXCEPTION, $data);
    }

    public function testLoggerGettersAndSetters()
    {
        $logger = $this->getMock('Psr\Log\LoggerInterface');

        $job = new JobTest();
        $this->assertSame(null, $job->getLogger());
        $this->assertSame($job, $job->setLogger($logger));
        $this->assertSame($logger, $job->getLogger());
    }

    public function testDataIsPassedToDoRun()
    {
        $data = $this->getMock('Alchemy\TaskManager\JobDataInterface');

        $job = new JobTest();
        $job->addSubscriber(new DurationLimitSubscriber(0.2));
        $job->run($data);

        $this->assertSame($data, $job->getData());
    }

    public function testSingleRunRunsAndStop()
    {
        $data = $this->getMock('Alchemy\TaskManager\JobDataInterface');

        $job = new JobTest();
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

    public function testEventsWithExceptionAreDispatchedOnSingleRun()
    {
        $data = $this->getMock('Alchemy\TaskManager\JobDataInterface');

        $job = new JobFailureTest();
        $collector = array();
        $job->addListener(TaskManagerEvents::START, function () use (&$collector) {
            $collector[] = TaskManagerEvents::START;
        });
        $job->addListener(TaskManagerEvents::TICK, function () use (&$collector) {
            $collector[] = TaskManagerEvents::TICK;
        });
        $job->addListener(TaskManagerEvents::EXCEPTION, function ($event) use (&$collector) {
            $collector[] = TaskManagerEvents::EXCEPTION;
        });
        $job->addListener(TaskManagerEvents::STOP, function () use (&$collector) {
            $collector[] = TaskManagerEvents::STOP;
        });

        try {
            $this->assertSame($job, $job->singleRun($data));
            $this->fail('A job failure exception should have been raised.');
        } catch (JobFailureException $e) {

        }
        $this->assertSame(TaskManagerEvents::START, $collector[0]);
        $this->assertSame(TaskManagerEvents::TICK, $collector[1]);
        $this->assertContains(TaskManagerEvents::EXCEPTION, $collector);
    }

    public function testDoRunWithoutdataIsOk()
    {
        $job = new JobTest();
        $job->addSubscriber(new DurationLimitSubscriber(0.1));
        $job->run();
    }

    public function testJobCanBeRestartedAfterAFailure()
    {
        $job = new JobFailureTest();
        try {
            $job->run();
            $this->fail('A JobFailureException should have been raised');
        } catch (JobFailureException $e) {

        }
        $job = new JobFailureTest();
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

class JobTestWithCustomEvent extends AbstractJob
{
    protected function doRun(JobDataInterface $data = null)
    {
        $this->dispatcher->dispatch('coucou', new Event());
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
