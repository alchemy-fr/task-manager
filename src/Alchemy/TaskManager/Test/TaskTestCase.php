<?php

namespace Alchemy\TaskManager\Test;

use Alchemy\TaskManager\TaskInterface;
use \PHPUnit\Framework\TestCase;

abstract class TaskTestCase extends TestCase
{
    public function testThatCreateProcessReturnsAProcessableInterface()
    {
        $task = $this->getTask();
        $this->assertInstanceOf('Symfony\Component\Process\Process', $task->createProcess());
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
