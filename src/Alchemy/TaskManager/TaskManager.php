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

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Process\Manager\ProcessManager;
use Symfony\Component\Process\ProcessInterface;

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
    /** @var ZMQSocket */
    private $listener;
    /** @var ZMQSocket */
    private $publisher;

    private $lockFile;
    private $lockDir;

    public function __construct(ZMQSocket $listener, ZMQSocket $publisher, LoggerInterface $logger, TaskListInterface $list)
    {
        $this->list = $list;
        $this->logger = $logger;
        $this->listener = $listener;
        $this->publisher = $publisher;
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

    public function getLockDirectory()
    {
        if (null === $this->lockDir) {
            return sys_get_temp_dir();
        }

        return $this->lockDir;
    }

    public function setLockDirectory($directory)
    {
        if (!is_dir($directory)) {
            throw new InvalidArgumentException('%s does not seem to be a directory.');
        }
        if (!is_writable($directory)) {
            throw new InvalidArgumentException('%s does not seem to be writeable.');
        }

        $this->lockDir = rtrim($directory, DIRECTORY_SEPARATOR);

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
    public function start()
    {
        $this->listener->bind();
        $this->publisher->bind();

        $this->manager->setDaemon(true);
        $this->updateProcesses();
        $this->manager->start();

        while ($this->manager->isRunning()) {
            $this->manager->signal(SIGCONT);
            $start = microtime(true);
            $this->poll();
            $this->publishData();
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
        if ($this->publisher->isBound()) {
            $this->publisher->unbind();
        }
        if (null !== $this->lockFile) {
            $this->lockFile->unlock();
        }

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
    public static function create(LoggerInterface $logger, TaskListInterface $list, array $options = array())
    {
        $options = array_replace(array(
            'listener_protocol'  => 'tcp',
            'listener_host'      => '127.0.0.1',
            'listener_port'      => 6660,
            'publisher_protocol' => 'tcp',
            'publisher_host'     => '127.0.0.1',
            'publisher_port'     => 6661,
        ), $options);

        $context = new \ZMQContext();
        $listener = new ZMQSocket(
            $context, \ZMQ::SOCKET_REP,
            $options['listener_protocol'],
            $options['listener_host'],
            $options['listener_port']
        );
        $publisher = new ZMQSocket(
            $context, \ZMQ::SOCKET_PUB,
            $options['publisher_protocol'],
            $options['publisher_host'],
            $options['publisher_port']
        );

        return new TaskManager($listener, $publisher, $logger, $list);
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
        while (false !== $message = $this->listener->recv(\ZMQ::MODE_NOBLOCK)) {
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
            $this->listener->send($recv);
            usleep(1000);
        }
    }

    /**
     * Publishes process data to ZMQ publisher socket.
     */
    private function publishData()
    {
        $data = array();
        foreach ($this->manager->getManagedProcesses() as $name => $process) {
            $data[$name] = array(
                'status' => $process->getStatus(),
                'pid'    => $process->getManagedProcess() instanceof ProcessInterface ? $process->getManagedProcess()->getPid() : null,
            );
        }
        if (false === $this->publisher->send(json_encode($data), defined('\ZMQ::MODE_DONTWAIT') ? \ZMQ::MODE_DONTWAIT : ZMQ::MODE_NOBLOCK)) {
            $this->logger->error('Unable to publish status, ZMQ is blocking');
        }
    }
}
