<?php

namespace Alchemy\Test\TaskManager\Event\Subscriber;

use Alchemy\TaskManager\Event\Subscriber\StopSignalSubscriber;
use Alchemy\TaskManager\Event\JobEvent;
use Neutron\SignalHandler\SignalHandler;

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

        $subscriber = new StopSignalSubscriber(SignalHandler::getInstance(), $logger);
        $subscriber->onJobStart(new JobEvent($job));
        declare(ticks=1);
        posix_kill(getmypid(), $signal);
        // required as the job is a mock, not running.
        // the handler must be removed after the signal (normally done by event).
        $subscriber->onJobStop(new JobEvent($job));
    }

    /**
     * @dataProvider provideHandledSignals
     */
    public function testSignalWithoutLogger($signal)
    {
        $job = $this->getMock('Alchemy\TaskManager\JobInterface');
        $job->expects($this->once())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(true));

        $subscriber = new StopSignalSubscriber(SignalHandler::getInstance());
        $subscriber->onJobStart(new JobEvent($job));
        declare(ticks=1);
        posix_kill(getmypid(), $signal);
        // required as the job is a mock, not running.
        // the handler must be removed after the signal (normally done by event).
        $subscriber->onJobStop(new JobEvent($job));
    }

    /**
     * @dataProvider provideHandledSignals
     */
    public function testOnJobTickDoesNothingIfJobIsNotStarted($signal)
    {
        $job = $this->getMock('Alchemy\TaskManager\JobInterface');
        $job->expects($this->never())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(false));

        $subscriber = new StopSignalSubscriber(SignalHandler::getInstance());
        $subscriber->onJobStart(new JobEvent($job));
        declare(ticks=1);
        posix_kill(getmypid(), $signal);
        // required as the job is a mock, not running.
        // the handler must be removed after the signal (normally done by event).
        $subscriber->onJobStop(new JobEvent($job));
    }

    public function provideHandledSignals()
    {
        return array(array(SIGINT), array(SIGTERM));
    }

    protected function getSubscriber()
    {
        return new StopSignalSubscriber(SignalHandler::getInstance());
    }
}
