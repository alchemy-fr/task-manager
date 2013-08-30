<?php

namespace Alchemy\TaskManager\Test;

use Alchemy\TaskManager\TaskInterface;

abstract class TaskTestCase extends \PHPUnit_Framework_TestCase
{
    public function testThatCreateProcessReturnsAProcessableInterface()
    {
        $task = $this->getTask();
        $this->assertInstanceOf('Symfony\Component\Process\ProcessableInterface', $task->createProcess());
    }

    public function testThatTheNameIsString()
    {
        $task = $this->getTask();
        $this->assertInternalType('string', $task->getName());
    }

    public function testThatTheIterationsIsPositiveValue()
    {
        $task = $this->getTask();
        $this->assertInternalType('integer', $task->getIterations());
        $this->assertGreaterThan(0, $task->getIterations());
    }

    /**
     * @return TaskInterface
     */
    abstract protected function getTask();
}
