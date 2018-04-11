<?php

namespace Alchemy\Test\TaskManager;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public function createJobMock()
    {
        return $this->createMock('Alchemy\TaskManager\Job\JobInterface');
    }

    public function createDataMock()
    {
        return $this->createMock('Alchemy\TaskManager\Job\JobDataInterface');
    }
}
