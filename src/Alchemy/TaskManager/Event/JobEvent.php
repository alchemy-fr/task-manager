<?php

/*
 * This file is part of Alchemy Task Manager
 *
 * (c) 2013 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\TaskManager\Event;

use Alchemy\TaskManager\JobInterface;
use Symfony\Component\EventDispatcher\Event;

class JobEvent extends Event
{
    private $job;

    public function __construct(JobInterface $job)
    {
        $this->job = $job;
    }

    /**
     * Returns the related job.
     *
     * @return JobInterface
     */
    public function getJob()
    {
        return $this->job;
    }
}
