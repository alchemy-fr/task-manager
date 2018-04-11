<?php

namespace Alchemy\Test\TaskManager\Event\JobSubscriber;

use Alchemy\TaskManager\Event\JobSubscriber\SignalControlledSubscriber;
use Alchemy\TaskManager\Event\JobEvent;
use Alchemy\TaskManager\Job\MessageJobData;
use Neutron\SignalHandler\SignalHandler;

class SignalControlledSubscriberTest extends SubscriberTestCase
{
    /**
     * @dataProvider provideInvalidPeriods
     * @expectedException Alchemy\TaskManager\Exception\InvalidArgumentException
     * @expectedExceptionMessage Signal period should be greater than 0.15 s.
     */
    public function testWithInvalidPeriod($limit)
    {
        new SignalControlledSubscriber(SignalHandler::getInstance(), $limit);
    }

    public function provideInvalidPeriods()
    {
        return array(array(0.10), array(-5));
    }

    public function testDoesNothingIfNotStarted()
    {
        $job = $this->createJobMock();
        $job->expects($this->never())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(true));

        $subscriber = new SignalControlledSubscriber(SignalHandler::getInstance(), 0.15);
        $subscriber->onJobTick(new JobEvent($job, $this->createDataMock()));
    }

    public function testWithLogger()
    {
        $job = $this->createJobMock();
        $job->expects($this->once())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(true));

        $logger = $this->createMock('Psr\Log\LoggerInterface');
        $logger->expects($this->once())->method('notice')->with('No signal received for romain since start-time (max period is 0.15 s.), stopping.');

        $subscriber = new SignalControlledSubscriber(SignalHandler::getInstance(), 0.15, $logger);
        $subscriber->onJobStart(new JobEvent($job, $this->createDataMock()));
        usleep(150000);
        $subscriber->onJobTick(new JobEvent($job, new MessageJobData('romain')));
    }

    public function testOnJobTickWithoutLogger()
    {
        $job = $this->createJobMock();
        $job->expects($this->once())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(true));

        $subscriber = new SignalControlledSubscriber(SignalHandler::getInstance(), 0.15);
        $subscriber->onJobStart(new JobEvent($job, $this->createDataMock()));
        usleep(150000);
        $subscriber->onJobTick(new JobEvent($job, $this->createDataMock()));
    }

    public function testOnJobTickMultiple()
    {
        $job = $this->createJobMock();
        $job->expects($this->never())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(true));

        $subscriber = new SignalControlledSubscriber(SignalHandler::getInstance(), 0.15);
        $subscriber->onJobStart(new JobEvent($job, $this->createDataMock()));
        usleep(100000);
        $subscriber->signalHandler(SIGCONT);
        $subscriber->onJobTick(new JobEvent($job, $this->createDataMock()));
        usleep(100000);
        $subscriber->signalHandler(SIGCONT);
        $subscriber->onJobTick(new JobEvent($job, $this->createDataMock()));
        usleep(100000);
        $subscriber->signalHandler(SIGCONT);
        $subscriber->onJobTick(new JobEvent($job, $this->createDataMock()));
        usleep(100000);
        $subscriber->signalHandler(SIGCONT);
        $subscriber->onJobTick(new JobEvent($job, $this->createDataMock()));
        usleep(100000);
        $subscriber->signalHandler(SIGCONT);
        $subscriber->onJobTick(new JobEvent($job, $this->createDataMock()));
    }

    public function testOnJobTickDoesNothingIfJobIsNotStarted()
    {
        $job = $this->createJobMock();
        $job->expects($this->never())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(false));

        $subscriber = new SignalControlledSubscriber(SignalHandler::getInstance(), 0.15);
        $subscriber->onJobStart(new JobEvent($job, $this->createDataMock()));
        usleep(150000);
        $subscriber->onJobTick(new JobEvent($job, $this->createDataMock()));
    }

    public function testOnJobTickWhenMemoryIsQuiteOk()
    {
        $job = $this->createJobMock();
        $job->expects($this->never())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(false));

        $subscriber = new SignalControlledSubscriber(SignalHandler::getInstance(), 0.15);
        $subscriber->onJobStart(new JobEvent($job, $this->createDataMock()));
        usleep(100000);
        $subscriber->onJobTick(new JobEvent($job, $this->createDataMock()));
    }

    protected function getSubscriber()
    {
        return new SignalControlledSubscriber(SignalHandler::getInstance());
    }
}
