<?php

namespace Alchemy\Test\TaskManager;

abstract class TestCase extends \PHPUnit_Framework_TestCase
{
    public function createJobMock()
    {
        return $this->getMock('Alchemy\TaskManager\Job\JobInterface');
    }

    public function createDataMock()
    {
        return $this->getMock('Alchemy\TaskManager\Job\JobDataInterface');
    }
}
