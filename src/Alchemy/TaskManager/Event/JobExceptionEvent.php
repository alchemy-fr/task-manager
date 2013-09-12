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

class JobExceptionEvent extends JobEvent
{
    private $exception;

    public function __construct(JobInterface $job, \Exception $e)
    {
        parent::__construct($job);
        $this->exception = $e;
    }

    /**
     * Returns the exception.
     *
     * @return \Exception
     */
    public function getException()
    {
        return $this->exception;
    }
}
