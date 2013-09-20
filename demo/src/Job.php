<?php

namespace Alchemy\TaskManager\Demo;

use Alchemy\TaskManager\AbstractJob;
use Alchemy\TaskManager\JobDataInterface;

class Job extends AbstractJob
{
    protected function doRun(JobDataInterface $data = null)
    {
        $time = microtime(true);
        while (microtime(true) < $time + 1) {
            usleep(10000);
        }
    }
}
