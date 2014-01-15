<?php

namespace Alchemy\Test\TaskManager\Event;

use Alchemy\TaskManager\Event\JobEvent;
use Alchemy\Test\TaskManager\TestCase;

class JobEventTest extends TestCase
{
    public function testJob()
    {
        $job = $this->getMock('Alchemy\TaskManager\JobInterface');
        $event = new JobEvent($job);
        $this->assertSame($job, $event->getJob());
    }
}
