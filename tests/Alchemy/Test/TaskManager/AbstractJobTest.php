<?php

namespace Alchemy\Test\TaskManager;

use Alchemy\TaskManager\AbstractJob;
use Alchemy\TaskManager\JobInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\PhpProcess;

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

        class Job extends Alchemy\TaskManager\AbstractJob
        {
            public function __construct()
            {
                $this->setId("laal");
                $this->setLockDirectory("' . $this->lockDir . '");
            }

            protected function doRun()
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

        class Job extends Alchemy\TaskManager\AbstractJob
        {
            private $data;

            public function __construct()
            {
                $this->setId("laal");
                $this->setLockDirectory("' . $this->lockDir . '");
                    $this->setMaxMemory(10*1024*1024);
            }

            protected function doRun()
            {
            declare(ticks=1);
                '.$extra.'
                usleep('.($time*10).'*100000);
            }
        }

            declare(ticks=1);
        $job = new Job();
        '.$conf.'
        $job->run();
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
     * @dataProvider provideVariousMemoryValues
     */
    public function testMaxMemory($max, $megPerSeconds, $expectedDuration)
    {
        $script = $this->getNonStoppingScript(1, ' $this->data .= str_repeat("x", '.$megPerSeconds.'*1024*1024);', '$job->setMaxMemory('.$max.'*1024*1024);$job->enableStopMode(Alchemy\TaskManager\JobInterface::MODE_STOP_ON_MEMORY);');
        $process1 = new PhpProcess($script);

        $start = microtime(true);
        $process1->run();

        $duration = microtime(true) - $start;

        $this->assertLessThan(0.15, abs($expectedDuration-$duration));
    }

    public function provideVariousMemoryValues()
    {
        return array(
            array(10, 10, 1),
            array(10, 5, 2),
            array(20, 20, 1),
            array(20, 10, 2),
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

        $this->assertLessThan(0.1, abs($max-$duration));
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
            $process1->signal(SIGUSR1);
            $this->assertTrue($process1->isRunning());
        }

        usleep($periodMilliseconds * 1000 * 3 / 2);
        $this->assertFalse($process1->isRunning());
    }

    public function provideVariousPeriods()
    {
        return array(
            array(100),
            array(300),
        );
    }

    private function getPauseScript()
    {
        return '<?php
        require "'.__DIR__.'/../../../../vendor/autoload.php";

        class Job extends Alchemy\TaskManager\AbstractJob
        {
            private $data;

            public function __construct()
            {
                $this->setId("laal");
                $this->setLockDirectory("' . $this->lockDir . '");
            }

            protected function doRun()
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

        class Job extends Alchemy\TaskManager\AbstractJob
        {
            private $data;

            public function __construct()
            {
                $this->setId("laal");
                $this->setLockDirectory("' . $this->lockDir . '");
            }

            protected function doRun()
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

    public function testPauseDoesAPause()
    {
        $script = $this->getPauseAndLoopScript();
        $process = new PhpProcess($script);

        $process->start();
        usleep(500000);
        $process->stop();
        $this->assertFalse($process->isRunning());
        $this->assertEquals("loop\nloop\nloop\nloop\nloop\n", $process->getOutput());
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
        $this->assertFalse($job->isStopMode(JobInterface::MODE_STOP_ON_MEMORY));
        $this->assertFalse($job->isStopMode(JobInterface::MODE_STOP_UNLESS_SIGNAL));
        $job->enableStopMode(JobInterface::MODE_STOP_ON_DURATION);
        $this->assertTrue($job->isStopMode(JobInterface::MODE_STOP_ON_DURATION));
        $this->assertFalse($job->isStopMode(JobInterface::MODE_STOP_ON_MEMORY));
        $this->assertFalse($job->isStopMode(JobInterface::MODE_STOP_UNLESS_SIGNAL));
        $job->enableStopMode(JobInterface::MODE_STOP_ON_MEMORY);
        $this->assertTrue($job->isStopMode(JobInterface::MODE_STOP_ON_DURATION));
        $this->assertTrue($job->isStopMode(JobInterface::MODE_STOP_ON_MEMORY));
        $this->assertFalse($job->isStopMode(JobInterface::MODE_STOP_UNLESS_SIGNAL));
        $job->enableStopMode(JobInterface::MODE_STOP_UNLESS_SIGNAL);
        $this->assertTrue($job->isStopMode(JobInterface::MODE_STOP_ON_DURATION));
        $this->assertTrue($job->isStopMode(JobInterface::MODE_STOP_ON_MEMORY));
        $this->assertTrue($job->isStopMode(JobInterface::MODE_STOP_UNLESS_SIGNAL));
        $job->disableStopMode(JobInterface::MODE_STOP_ON_MEMORY);
        $this->assertTrue($job->isStopMode(JobInterface::MODE_STOP_ON_DURATION));
        $this->assertFalse($job->isStopMode(JobInterface::MODE_STOP_ON_MEMORY));
        $this->assertTrue($job->isStopMode(JobInterface::MODE_STOP_UNLESS_SIGNAL));
        $job->disableStopMode(JobInterface::MODE_STOP_ON_DURATION);
        $job->disableStopMode(JobInterface::MODE_STOP_UNLESS_SIGNAL);
        $this->assertFalse($job->isStopMode(JobInterface::MODE_STOP_ON_DURATION));
        $this->assertFalse($job->isStopMode(JobInterface::MODE_STOP_ON_MEMORY));
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

    public function testMaxMemoryGettersAndSetters()
    {
        $job = new JobTest();
        $this->assertSame(null, $job->getMaxMemory());
        $this->assertSame($job, $job->setMaxMemory(2048));
        $this->assertSame(2048, $job->getMaxMemory());
    }

    /**
     * @dataProvider provideInvalidSignalPeriodValues
     * @expectedException Alchemy\TaskManager\Exception\InvalidArgumentException
     * @expectedExceptionMessage Maximum memory should be a positive value.
     */
    public function testInvalidMaxMemoryValues($memory)
    {
        $job = new JobTest();
        $job->setMaxMemory($memory);
    }

    public function provideInvalidMemoryValues()
    {
        return array(array(0), array(-20));
    }

    public function testMaxDurationGettersAndSetters()
    {
        $job = new JobTest();
        $this->assertSame(null, $job->getMaxDuration());
        $this->assertSame($job, $job->setMaxDuration(24));
        $this->assertSame((float) 24, $job->getMaxDuration());
    }

    /**
     * @dataProvider provideInvalidSignalPeriodValues
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
        $this->assertSame(null, $job->getSignalPeriod());
        $this->assertSame($job, $job->setSignalPeriod(42));
        $this->assertSame(42, $job->getSignalPeriod());
    }

    /**
     * @dataProvider provideInvalidSignalPeriodValues
     * @expectedException Alchemy\TaskManager\Exception\InvalidArgumentException
     * @expectedExceptionMessage Signal period should be a positive value.
     */
    public function testInvalidSignalPeriod($period)
    {
        $job = new JobTest();
        $job->setSignalPeriod($period);
    }

    public function provideInvalidSignalPeriodValues()
    {
        return array(array(0), array(-2));
    }
}

class JobTest extends AbstractJob
{
    protected function doRun($execution = null)
    {
        if (is_callable($execution)) {
            return call_user_func($execution);
        }
    }
}
