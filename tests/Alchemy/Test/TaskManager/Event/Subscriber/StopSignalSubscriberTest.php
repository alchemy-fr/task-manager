<?php

namespace Alchemy\Test\TaskManager\Event\Subscriber;

use Alchemy\TaskManager\Event\Subscriber\StopSignalSubscriber;
use Alchemy\TaskManager\Event\JobEvent;

class StopSignalSubscriberTest extends SubscriberTestCase
{
    /**
     * @dataProvider provideHandledSignals
     */
    public function testSignalWithLogger($signal)
    {
        $job = $this->getMock('Alchemy\TaskManager\JobInterface');
        $job->expects($this->once())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(true));

        $logger = $this->getMock('Psr\Log\LoggerInterface');
        $logger->expects($this->once())->method('info');

        $subscriber = new StopSignalSubscriber($logger);
        $subscriber->onJobStart(new JobEvent($job));
        declare(ticks=1);
        posix_kill(getmypid(), $signal);
    }

    /**
     * @dataProvider provideHandledSignals
     */
    public function testSignalWithoutLogger($signal)
    {
        $job = $this->getMock('Alchemy\TaskManager\JobInterface');
        $job->expects($this->once())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(true));

        $subscriber = new StopSignalSubscriber();
        $subscriber->onJobStart(new JobEvent($job));
        declare(ticks=1);
        posix_kill(getmypid(), $signal);
    }

    /**
     * @dataProvider provideHandledSignals
     */
    public function testOnJobTickDoesNothingIfJobIsNotStarted($signal)
    {
        $job = $this->getMock('Alchemy\TaskManager\JobInterface');
        $job->expects($this->never())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(false));

        $subscriber = new StopSignalSubscriber();
        $subscriber->onJobStart(new JobEvent($job));
        declare(ticks=1);
        posix_kill(getmypid(), $signal);
    }

    public function provideHandledSignals()
    {
        return array(array(SIGINT), array(SIGTERM));
    }

    protected function getSubscriber()
    {
        return new StopSignalSubscriber();
    }
}
