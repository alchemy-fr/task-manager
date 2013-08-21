<?php

namespace Alchemy\TaskManager\Demo;

use Alchemy\TaskManager\AbstractJob;

class Job extends AbstractJob
{
    public function __construct()
    {
        $this->setId('runnable');
    }

    protected function doRun()
    {
        $time = microtime(true);
        while (microtime(true) < $time + 1) {
            usleep(10000);
        }
    }
}
