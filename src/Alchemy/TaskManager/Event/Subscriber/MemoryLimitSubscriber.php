<?php

/*
 * This file is part of Alchemy Task Manager
 *
 * (c) 2013 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\TaskManager\Event\Subscriber;

use Alchemy\TaskManager\Event\JobEvent;
use Alchemy\TaskManager\Event\TaskManagerEvents;
use Alchemy\TaskManager\Exception\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Stops a Job in case a memory limit has been reached.
 */
class MemoryLimitSubscriber implements EventSubscriberInterface
{
    private $limit;
    private $logger;

    public function __construct($limit = 32E6, LoggerInterface $logger = null)
    {
        if (0 >= $limit) {
            throw new InvalidArgumentException('Maximum memory should be a positive value.');
        }

        $this->limit = (integer) $limit;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents()
    {
        return array(
            TaskManagerEvents::TICK => 'onJobTick',
        );
    }

    public function onJobTick(JobEvent $event)
    {
        if (!$event->getJob()->isStarted()) {
            return;
        }

        if (memory_get_usage() > $this->limit) {
            if (null !== $this->logger) {
                $this->logger->info(sprintf('Max memory reached (%d o.), stopping.', $this->limit));
            }
            $event->getJob()->stop();
        }
    }
}
