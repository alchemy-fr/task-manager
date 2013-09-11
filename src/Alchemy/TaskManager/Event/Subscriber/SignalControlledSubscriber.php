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
use Neutron\SignalHandler\SignalHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Stops a Job in case a SIGCONT signal is not received during a period.
 */
class SignalControlledSubscriber implements EventSubscriberInterface
{
    private $period;
    private $handler;
    private $namespace;
    private $logger;
    private $startTime;
    private $lastSignalTime;

    public function __construct(SignalHandler $handler, $period = 1, LoggerInterface $logger = null)
    {
        // use 3x the step used for pause
        if (0.15 > $period) {
            throw new InvalidArgumentException('Signal period should be greater than 0.15 s.');
        }

        $this->period = (float) $period;
        $this->logger = $logger;
        $this->handler = $handler;
        $this->namespace = uniqid('sigcontsignal', true) . microtime(true);
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
        $this->lastSignalTime = null;
        $this->handler->register(SIGCONT, array($this, 'signalHandler'), $this->namespace);
    }

    public function onJobStop(JobEvent $event)
    {
        $this->removeHandler();
        $this->startTime = null;
        $this->lastSignalTime = null;
    }

    public function onJobTick(JobEvent $event)
    {
        if (!$event->getJob()->isStarted()) {
            return;
        }

        if (null === $this->lastSignalTime) {
            if (null !== $this->startTime && (microtime(true) - $this->startTime) > $this->period) {
                if (null !== $this->logger) {
                    $this->logger->debug(sprintf('No signal received since start-time (max period is %s s.), stopping.', $this->period));
                }
                $event->getJob()->stop();
            }
        } elseif (null !== $this->startTime && (microtime(true) - $this->lastSignalTime) > $this->period) {
            if (null !== $this->logger) {
                $this->logger->debug(sprintf('No signal received since %s, (max period is %s s.), stopping.', (microtime(true) - $this->lastSignalTime), $this->period));
            }
            $event->getJob()->stop();
        }
    }

    /**
     * Signal handler for the subscriber.
     *
     * @param integer $signal
     */
    public function signalHandler($signal)
    {
        if (SIGCONT === $signal) {
            $this->lastSignalTime = microtime(true);
        }
    }

    private function removeHandler()
    {
        $this->handler->unregisterNamespace($this->namespace);
    }
}
