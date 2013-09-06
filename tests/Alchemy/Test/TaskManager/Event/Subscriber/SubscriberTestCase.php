<?php

namespace Alchemy\Test\TaskManager\Event\Subscriber;

use Alchemy\TaskManager\Event\TaskManagerEvents;

abstract class SubscriberTestCase extends \PHPUnit_Framework_TestCase
{
    public function testThatEventsAreRecognized()
    {
        foreach (array_keys($this->getSubscriber()->getSubscribedEvents()) as $name) {
            $this->assertContains($name, array(
                TaskManagerEvents::START,
                TaskManagerEvents::TICK,
                TaskManagerEvents::STOP,
            ));
        }
    }

    abstract protected function getSubscriber();
}
