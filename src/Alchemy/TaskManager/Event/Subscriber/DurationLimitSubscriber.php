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
 * Stops a Job in case a maximum duration has been reached.
 */
class DurationLimitSubscriber implements EventSubscriberInterface
{
    private $limit;
    private $logger;
    private $startTime;

    public function __construct($limit = 1800, LoggerInterface $logger = null)
    {
        if (0 >= $limit) {
            throw new InvalidArgumentException('Maximum duration should be a positive value.');
        }

        $this->limit = (float) $limit;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents()
    {
        return array(
            TaskManagerEvents::START     => 'onJobStart',
            TaskManagerEvents::TICK      => 'onJobTick',
            TaskManagerEvents::STOP      => 'onJobStop',
            TaskManagerEvents::EXCEPTION => 'onJobStop',
        );
    }

    public function onJobStart(JobEvent $event)
    {
        $this->startTime = microtime(true);
    }

    public function onJobStop(JobEvent $event)
    {
        $this->startTime = null;
    }

    public function onJobTick(JobEvent $event)
    {
        if (!$event->getJob()->isStarted()) {
            return;
        }

        if (null !== $this->startTime && (microtime(true) - $this->startTime) > $this->limit) {
            if (null !== $this->logger) {
                $this->logger->debug(sprintf('Max duration reached (%d s.), stopping.', $this->limit));
            }
            $event->getJob()->stop();
        }
    }
}
