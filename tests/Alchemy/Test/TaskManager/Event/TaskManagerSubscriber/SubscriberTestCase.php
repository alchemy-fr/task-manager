<?php

namespace Alchemy\Test\TaskManager\Event\TaskManagerSubscriber;

use Alchemy\TaskManager\Event\TaskManagerEvents;

abstract class SubscriberTestCase extends \PHPUnit_Framework_TestCase
{
    public function testThatEventsAreRecognized()
    {
        foreach (array_keys($this->getSubscriber()->getSubscribedEvents()) as $name) {
            $this->assertContains($name, array(
                TaskManagerEvents::MANAGER_START,
                TaskManagerEvents::MANAGER_STOP,
                TaskManagerEvents::MANAGER_REQUEST,
            ));
        }
    }

    abstract protected function getSubscriber();
}
