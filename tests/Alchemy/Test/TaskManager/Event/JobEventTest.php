<?php

namespace Alchemy\Test\TaskManager\Event;

use Alchemy\TaskManager\Event\JobEvent;

class JobEventTest extends \PHPUnit_Framework_TestCase
{
    public function testJob()
    {
        $job = $this->getMock('Alchemy\TaskManager\JobInterface');
        $event = new JobEvent($job);
        $this->assertSame($job, $event->getJob());
    }
}
