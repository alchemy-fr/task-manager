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
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Stops a Job in case a SIGINT or SIGTERM is received.
 */
class StopSignalSubscriber implements EventSubscriberInterface
{
    private $logger;

    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    public static function getSubscribedEvents()
    {
        return array(
            TaskManagerEvents::START => 'onJobStart',
            TaskManagerEvents::STOP => 'onJobStop',
        );
    }

    public function onJobStart(JobEvent $event)
    {
        $logger = $this->logger;
        $callback = function ($signal) use ($event, $logger) {
            if ($event->getJob()->isStarted()) {
                if (null !== $logger) {
                    $logger->info(sprintf('Caught stop signal `%d`, stopping', $signal));
                }
                $event->getJob()->stop();
            }
        };

        pcntl_signal(SIGTERM, $callback);
        pcntl_signal(SIGINT, $callback);
    }

    public function onJobStop(JobEvent $event)
    {
        pcntl_signal(SIGTERM, function () {});
        pcntl_signal(SIGINT, function () {});
    }
}
