<?php

namespace Alchemy\Test\TaskManager\Event\Subscriber;

use Alchemy\TaskManager\Event\Subscriber\MemoryLimitSubscriber;
use Alchemy\TaskManager\Event\JobEvent;

class MemoryLimitSubscriberTest extends SubscriberTestCase
{
    /**
     * @dataProvider provideInvalidMemoryLimits
     * @expectedException Alchemy\TaskManager\Exception\InvalidArgumentException
     * @expectedExceptionMessage Maximum memory should be a positive value.
     */
    public function testWithInvalidLimits($limit)
    {
        new MemoryLimitSubscriber($limit);
    }

    public function provideInvalidMemoryLimits()
    {
        return array(array(0), array(-5));
    }

    public function testOnJobTickWithLogger()
    {
        $job = $this->getMock('Alchemy\TaskManager\JobInterface');
        $job->expects($this->once())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(true));

        $logger = $this->getMock('Psr\Log\LoggerInterface');
        $logger->expects($this->once())->method('debug');

        $subscriber = new MemoryLimitSubscriber(1, $logger);
        $subscriber->onJobTick(new JobEvent($job));
    }

    public function testOnJobTickWithoutLogger()
    {
        $job = $this->getMock('Alchemy\TaskManager\JobInterface');
        $job->expects($this->once())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(true));

        $subscriber = new MemoryLimitSubscriber(1);
        $subscriber->onJobTick(new JobEvent($job));
    }

    public function testOnJobTickDoesNothingIfJobIsNotStarted()
    {
        $job = $this->getMock('Alchemy\TaskManager\JobInterface');
        $job->expects($this->never())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(false));

        $subscriber = new MemoryLimitSubscriber(1);
        $subscriber->onJobTick(new JobEvent($job));
    }

    public function testOnJobTickWhenMemoryIsQuiteOk()
    {
        $job = $this->getMock('Alchemy\TaskManager\JobInterface');
        $job->expects($this->never())->method('stop');
        $job->expects($this->any())->method('isStarted')->will($this->returnValue(true));

        $subscriber = new MemoryLimitSubscriber(memory_get_usage() + 1<<20);
        $subscriber->onJobTick(new JobEvent($job));
    }

    protected function getSubscriber()
    {
        return new MemoryLimitSubscriber();
    }
}
