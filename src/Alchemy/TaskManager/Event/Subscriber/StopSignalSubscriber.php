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
use Neutron\SignalHandler\SignalHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Stops a Job in case a SIGINT or SIGTERM is received.
 */
class StopSignalSubscriber implements EventSubscriberInterface
{
    private $logger;
    private $signalHandler;
    private $namespace;

    public function __construct(SignalHandler $signalHandler, LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        $this->signalHandler = $signalHandler;
        $this->namespace = uniqid('stopsignal', true) . microtime(true);
    }

    public static function getSubscribedEvents()
    {
        return array(
            TaskManagerEvents::START     => 'onJobStart',
            TaskManagerEvents::STOP      => 'onJobStop',
            TaskManagerEvents::EXCEPTION => 'onJobStop',
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

        $this->signalHandler->register(array(SIGTERM, SIGINT), $callback, $this->namespace);
    }

    public function onJobStop(JobEvent $event)
    {
        $this->signalHandler->unregisterNamespace($this->namespace);
    }
}
