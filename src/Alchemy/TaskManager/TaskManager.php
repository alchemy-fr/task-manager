<?php

/*
 * This file is part of Alchemy Task Manager
 *
 * (c) 2013 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\TaskManager;

use Alchemy\TaskManager\Event\TaskManagerEvent;
use Alchemy\TaskManager\Event\TaskManagerRequestEvent;
use Alchemy\TaskManager\Event\TaskManagerEvents;
use Alchemy\TaskManager\Event\TaskManagerSubscriber\StatusRequestSubscriber;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Process\Manager\ProcessManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class TaskManager implements LoggerAwareInterface
{
    const MESSAGE_PING = 'PING';
    const MESSAGE_STATE = 'STATE';
    const MESSAGE_STOP = 'STOP';
    const MESSAGE_PROCESS_UPDATE = 'UPDATE';

    const RESPONSE_PONG = 'PONG';
    const RESPONSE_OK = 'OK';
    const RESPONSE_UNHANDLED_MESSAGE = 'UNHANDLED MESSAGE';

    /** @var Logger */
    private $logger;
    /** @var TaskListInterface */
    private $list;
    /** @var ProcessManager */
    private $manager;
    /** @var ZMQSocket */
    private $listener;
    /** @var EventDispatcherInterface */
    private $dispatcher;

    public function __construct(EventDispatcherInterface $dispatcher, ZMQSocket $listener, LoggerInterface $logger, TaskListInterface $list)
    {
        $this->dispatcher = $dispatcher;
        $this->list = $list;
        $this->logger = $logger;
        $this->listener = $listener;
        $this->manager = new ProcessManager($logger, null, ProcessManager::STRATEGY_IGNORE, ProcessManager::STRATEGY_IGNORE);
        $this->dispatcher->addSubscriber(new StatusRequestSubscriber());
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Adds a listener to the task manager.
     *
     * @param string $eventName
     * @param callable $listener
     *
     * @return TaskManager
     */
    public function addListener($eventName, $listener)
    {
        $this->dispatcher->addListener($eventName, $listener);

        return $this;
    }

    /**
     * Adds an event subscriber to the task manager.
     *
     * @param EventSubscriberInterface $subscriber
     *
     * @return TaskManager
     */
    public function addSubscriber(EventSubscriberInterface $subscriber)
    {
        $this->dispatcher->addSubscriber($subscriber);

        return $this;
    }

    /**
     * Gets the logger.
     *
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Sets the logger.
     *
     * @param LoggerInterface $logger
     *
     * @return TaskManager
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Returns the underlying process manager.
     *
     * @return ProcessManager
     */
    public function getProcessManager()
    {
        return $this->manager;
    }

    /**
     * Starts the task manager, opens a listener on the given host and port.
     *
     * @param string  $host
     * @param integer $port
     *
     * @return TaskManager
     */
    public function start()
    {
        $this->dispatcher->dispatch(TaskManagerEvents::MANAGER_START, new TaskManagerEvent($this));
        $this->listener->bind();

        $this->manager->setDaemon(true);
        $this->updateProcesses();
        $this->manager->start();

        while ($this->manager->isRunning()) {
            $this->manager->signal(SIGCONT);
            $start = microtime(true);
            $this->poll();
            $this->dispatcher->dispatch(TaskManagerEvents::MANAGER_TICK, new TaskManagerEvent($this));
            // sleep at list 10ms, at max 100ms
            usleep(max(0.1 - (microtime(true) - $start), 0.01) * 1E6);
        }

        return $this;
    }

    /**
     * Stopes the task manager.
     *
     * @param integer $timeout A timeout
     * @param integer $signal  A signal to send at the end of the timeout.
     *
     * @return TaskManager
     */
    public function stop($timeout = 10, $signal = null)
    {
        if ($this->manager->isRunning()) {
            $this->logger->notice("Stopping process manager ...");
            $this->manager->stop($timeout, $signal);
        }
        if ($this->listener->isBound()) {
            $this->listener->unbind();
        }
        $this->dispatcher->dispatch(TaskManagerEvents::MANAGER_STOP, new TaskManagerEvent($this));

        return $this;
    }

    /**
     * Creates a taskManager.
     *
     * @param LoggerInterface   $logger
     * @param TaskListInterface $list
     * @param array             $options
     *
     * @return TaskManager
     */
    public static function create(EventDispatcherInterface $dispatcher, LoggerInterface $logger, TaskListInterface $list, array $options = array())
    {
        $options = array_replace(array(
            'listener_protocol'  => 'tcp',
            'listener_host'      => '127.0.0.1',
            'listener_port'      => 6660,
        ), $options);

        $context = new \ZMQContext();
        $listener = new ZMQSocket(
            $context, \ZMQ::SOCKET_REP,
            $options['listener_protocol'],
            $options['listener_host'],
            $options['listener_port']
        );

        return new TaskManager($dispatcher, $listener, $logger, $list);
    }

    /**
     * Updates the process, according to the task list.
     */
    private function updateProcesses()
    {
        $names = array_keys($this->manager->getManagedProcesses());

        foreach ($this->list->refresh() as $task) {
            if (!$this->manager->has($task->getName())) {
                $this->manager->add($task->createProcess(), $task->getName(), $task->getIterations());
            }
            if (false !== $offset = array_search($task->getName(), $names, true)) {
                unset($names[$offset]);
            }
        }

        foreach ($names as $name) {
            if ($this->manager->has($name)) {
                $this->manager->remove($name);
            }
        }
    }

    /**
     * Polls ZMQ socket for messages.
     */
    private function poll()
    {
        while (false !== $message = $this->listener->recv(defined('ZMQ::MODE_DONTWAIT') ? \ZMQ::MODE_DONTWAIT : \ZMQ::MODE_NOBLOCK)) {
            switch ($message) {
                case static::MESSAGE_PING:
                    $reply = static::RESPONSE_PONG;
                    break;
                case static::MESSAGE_STOP:
                    $this->manager->stop();
                    $reply = static::RESPONSE_OK;
                    break;
                case static::MESSAGE_PROCESS_UPDATE:
                    $this->updateProcesses();
                    $reply = static::RESPONSE_OK;
                    break;
                default:
                    $reply = static::RESPONSE_UNHANDLED_MESSAGE;
                    break;
            }
            $event = new TaskManagerRequestEvent($this, $message, $reply);
            $this->logger->debug(sprintf('Received message "%s"', $message));
            $this->dispatcher->dispatch(TaskManagerEvents::MANAGER_REQUEST, $event);
            $this->listener->send(json_encode(array("request" => $message, "reply" => $event->getResponse())));
            usleep(1000);
        }
    }
}
