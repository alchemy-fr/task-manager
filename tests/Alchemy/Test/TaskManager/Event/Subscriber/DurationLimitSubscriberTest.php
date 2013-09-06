<?php

namespace Alchemy\Test\TaskManager\Event\Subscriber;

use Alchemy\TaskManager\Event\Subscriber\DurationLimitSubscriber;
use Alchemy\TaskManager\Event\JobEvent;

class DurationLimitSubscriberTest extends SubscriberTestCase
{
    /**
     * @dataProvider provideInvalidDurationLimits
     * @expectedException Alchemy\TaskManager\Exception\InvalidArgumentException
     * @expectedExceptionMessage Maximum duration should be a positive value.
     */
    public function testWithInvalidLimits($limit)
    {
        new DurationLimitSubscriber($limit);
    }

    public function provideInvalidDurationLimits()
    {
        return array(array(0), array(-5));
    }

    public function testDoesNothingIfNotStarted()
    {
        $job = $this->getMock('Alchemy\TaskManager\JobInterface');
        $job->expects($this->never())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(true));

        $subscriber = new DurationLimitSubscriber(0.1);
        $subscriber->onJobTick(new JobEvent($job));
    }

    public function testWithLogger()
    {
        $job = $this->getMock('Alchemy\TaskManager\JobInterface');
        $job->expects($this->once())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(true));

        $logger = $this->getMock('Psr\Log\LoggerInterface');
        $logger->expects($this->once())->method('debug');

        $subscriber = new DurationLimitSubscriber(0.1, $logger);
        $subscriber->onJobStart(new JobEvent($job));
        usleep(100000);
        $subscriber->onJobTick(new JobEvent($job));
    }

    public function testOnJobTickWithoutLogger()
    {
        $job = $this->getMock('Alchemy\TaskManager\JobInterface');
        $job->expects($this->once())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(true));

        $subscriber = new DurationLimitSubscriber(0.1);
        $subscriber->onJobStart(new JobEvent($job));
        usleep(100000);
        $subscriber->onJobTick(new JobEvent($job));
    }

    public function testOnJobTickDoesNothingIfJobIsNotStarted()
    {
        $job = $this->getMock('Alchemy\TaskManager\JobInterface');
        $job->expects($this->never())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(false));

        $subscriber = new DurationLimitSubscriber(0.1);
        $subscriber->onJobStart(new JobEvent($job));
        usleep(100000);
        $subscriber->onJobTick(new JobEvent($job));
    }

    public function testOnJobTickWhenMemoryIsQuiteOk()
    {
        $job = $this->getMock('Alchemy\TaskManager\JobInterface');
        $job->expects($this->never())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(false));

        $subscriber = new DurationLimitSubscriber(0.1);
        $subscriber->onJobStart(new JobEvent($job));
        usleep(50000);
        $subscriber->onJobTick(new JobEvent($job));
    }

    protected function getSubscriber()
    {
        return new DurationLimitSubscriber();
    }
}
