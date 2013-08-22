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

use Alchemy\TaskManager\Exception\RuntimeException;
use Symfony\Component\Process\Manager\ProcessManager;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareInterface;

class TaskManager implements LoggerAwareInterface
{
    const MESSAGE_PING = 'PING';
    const MESSAGE_STOP = 'STOP';
    const MESSAGE_PROCESS_UPDATE = 'UPDATE';

    const RESPONSE_PONG = 'PONG';
    const RESPONSE_OK = 'OK';
    const RESPONSE_INVALID_MESSAGE = 'INVALID MESSAGE';

    /** @var Logger */
    private $logger;
    /** @var TaskListInterface */
    private $list;
    /** @var ProcessManager */
    private $manager;
    /** @var \ZMQSocket */
    private $socket;
    /** @var string */
    private $dsn;

    public function __construct(\ZMQSocket $socket, LoggerInterface $logger, TaskListInterface $list)
    {
        $this->list = $list;
        $this->logger = $logger;
        $this->socket = $socket;
        $this->manager = new ProcessManager($logger, null, ProcessManager::STRATEGY_IGNORE, ProcessManager::STRATEGY_IGNORE);
    }

    public function __destruct()
    {
        $this->stop();
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
     * Starts the task manager, opens a listener on the given host and port.
     *
     * @param string  $host
     * @param integer $port
     *
     * @return TaskManager
     */
    public function start($host = '127.0.0.1', $port = 6660)
    {
        $this->dsn = "tcp://$host:$port";
        try {
            $this->socket->bind($this->dsn);
        } catch (\ZMQSocketException $e) {
            $this->log('error', sprintf('Unable to bind ZMQ socket : %s', $e->getMessage()));
            throw new RuntimeException('Unable to bind ZMQ socket', $e->getCode(), $e);
        }
        $this->manager->setDaemon(true);
        $this->updateProcesses();
        $this->manager->start();

        while ($this->manager->isRunning()) {
            $this->manager->signal(SIGCONT);
            $start = microtime(true);
            $this->poll();
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
        if (null !== $this->socket && null !== $this->dsn) {
            $this->logger->notice("Unbinding socket $this->dsn ...");
            try {
                $this->socket->unbind($this->dsn);
            } catch (\ZMQSocketException $e) {
                $this->log('error', sprintf('Unable to unbind ZMQ socket : %s', $e->getMessage()));
            }
            $this->dsn = null;
            $this->socket = null;
        }

        return $this;
    }

    /**
     * Logs a message
     *
     * @param string $method  A valid LoggerInterface method
     * @param string $message The message to be logged
     *
     * @return TaskManager
     */
    private function log($method, $message)
    {
        if ($this->logger) {
            call_user_func(array($this->logger, $method), $message);
        }

        return $this;
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
        while (false !== $message = $this->socket->recv(\ZMQ::MODE_NOBLOCK)) {
            switch ($message) {
                case static::MESSAGE_PING:
                    $this->logger->debug(sprintf('Received message "%s"', $message));
                    $recv = static::RESPONSE_PONG;
                    break;
                case static::MESSAGE_STOP:
                    $this->logger->debug(sprintf('Received message "%s"', $message));
                    $this->manager->stop();
                    $recv = static::RESPONSE_OK;
                    break;
                case static::MESSAGE_PROCESS_UPDATE:
                    $this->logger->debug(sprintf('Received message "%s"', $message));
                    $this->updateProcesses();
                    $recv = static::RESPONSE_OK;
                    break;
                default:
                    $this->logger->error(sprintf('Invalid message "%s" received', $message));
                    $recv = static::RESPONSE_INVALID_MESSAGE;
                    break;
            }
            $this->socket->send($recv);
            usleep(1000);
        }
    }
}
