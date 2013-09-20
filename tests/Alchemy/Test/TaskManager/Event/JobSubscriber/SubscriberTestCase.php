<?php

namespace Alchemy\Test\TaskManager\Event\JobSubscriber;

use Alchemy\TaskManager\Event\JobEvents;

abstract class SubscriberTestCase extends \PHPUnit_Framework_TestCase
{
    public function testThatEventsAreRecognized()
    {
        foreach (array_keys($this->getSubscriber()->getSubscribedEvents()) as $name) {
            $this->assertContains($name, array(
                JobEvents::START,
                JobEvents::TICK,
                JobEvents::STOP,
                JobEvents::EXCEPTION,
            ));
        }
    }

    abstract protected function getSubscriber();
}
