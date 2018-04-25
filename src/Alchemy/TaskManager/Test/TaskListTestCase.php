<?php

namespace Alchemy\TaskManager\Test;

use Alchemy\TaskManager\TaskListInterface;
use \PHPUnit\Framework\TestCase;

abstract class TaskListTestCase extends TestCase
{
    public function testThatRefreshReturnsAnArrayOfTaskInterface()
    {
        $list = $this->getTaskList();
        foreach ($list->refresh() as $task) {
            $this->assertInstanceOf('Alchemy\TaskManager\TaskInterface', $task);
        }
    }

    /**
     * @return TaskListInterface
     */
    abstract protected function getTaskList();
}
