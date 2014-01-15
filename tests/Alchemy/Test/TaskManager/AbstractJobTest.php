<?php

namespace Alchemy\Test\TaskManager;

use Alchemy\TaskManager\AbstractJob;
use Alchemy\TaskManager\JobDataInterface;
use Alchemy\Test\TaskManager\PhpProcess;
use Alchemy\TaskManager\Event\JobEvents;
use Alchemy\TaskManager\Event\JobSubscriber\DurationLimitSubscriber;
use Symfony\Component\Finder\Finder;
use Symfony\Component\EventDispatcher\Event;

class AbstractJobTest extends TestCase
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

    public function testStopDispatchAnEventOnFirstCall()
    {
        $job = new JobTest();
        $counter = 0;
        $job->addListener(JobEvents::STOP_REQUEST, function () use (&$counter) { $counter++; });
        $job->stop();
        $this->assertEquals(0, $counter);
        $job->addSubscriber(new DurationLimitSubscriber(0.1));
        $job->run();
        $this->assertEquals(1, $counter);
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
        use Alchemy\TaskManager\Event\JobEvents;

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
        $job->addSubscriber(new Alchemy\TaskManager\Event\JobSubscriber\StopSignalSubscriber(Neutron\SignalHandler\SignalHandler::getInstance()));
        $job->addListener(JobEvents::START, function () { echo "job-start\n"; });
        $job->addListener(JobEvents::TICK, function () { echo "job-tick\n"; });
        $job->addListener(JobEvents::STOP, function () { echo "job-stop\n"; });
        $job->addListener(JobEvents::EXCEPTION, function () { echo "job-exception\n"; });
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
        $this->assertSame(JobEvents::START, $data[0]);
        $this->assertSame(JobEvents::TICK, $data[1]);
        $this->assertContains(JobEvents::STOP, $data);
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
        $this->assertSame(JobEvents::START, $data[0]);
        $this->assertSame(JobEvents::TICK, $data[1]);
        $this->assertContains(JobEvents::EXCEPTION, $data);
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
        $job->addListener(JobEvents::START, function () use (&$collector) {
            $collector[] = JobEvents::START;
        });
        $job->addListener(JobEvents::TICK, function () use (&$collector) {
            $collector[] = JobEvents::TICK;
        });
        $job->addListener(JobEvents::STOP, function () use (&$collector) {
            $collector[] = JobEvents::STOP;
        });

        $this->assertSame($job, $job->singleRun($data));
        $this->assertSame(JobEvents::START, $collector[0]);
        $this->assertSame(JobEvents::TICK, $collector[1]);
        $this->assertContains(JobEvents::STOP, $collector);
    }

    public function testEventsWithExceptionAreDispatchedOnSingleRun()
    {
        $data = $this->getMock('Alchemy\TaskManager\JobDataInterface');

        $job = new JobFailureTest();
        $collector = array();
        $job->addListener(JobEvents::START, function () use (&$collector) {
            $collector[] = JobEvents::START;
        });
        $job->addListener(JobEvents::TICK, function () use (&$collector) {
            $collector[] = JobEvents::TICK;
        });
        $job->addListener(JobEvents::EXCEPTION, function ($event) use (&$collector) {
            $collector[] = JobEvents::EXCEPTION;
        });
        $job->addListener(JobEvents::STOP, function () use (&$collector) {
            $collector[] = JobEvents::STOP;
        });

        try {
            $this->assertSame($job, $job->singleRun($data));
            $this->fail('A job failure exception should have been raised.');
        } catch (JobFailureException $e) {

        }
        $this->assertSame(JobEvents::START, $collector[0]);
        $this->assertSame(JobEvents::TICK, $collector[1]);
        $this->assertContains(JobEvents::EXCEPTION, $collector);
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
