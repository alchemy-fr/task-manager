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

use Alchemy\TaskManager\Event\JobEvent;
use Alchemy\TaskManager\Event\JobExceptionEvent;
use Alchemy\TaskManager\Event\TaskManagerEvents;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

abstract class AbstractJob implements JobInterface
{
    /** @var null|string */
    private $status;
    /** @var null|LoggerInterface */
    private $logger;
    /** @var EventDispatcherInterface */
    private $dispatcher;

    public function __construct(EventDispatcherInterface $dispatcher = null)
    {
        if (null === $dispatcher) {
            $dispatcher = new EventDispatcher();
        }
        $this->dispatcher = $dispatcher;
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    /**
     * {@inheritdoc}
     */
    public function addListener($eventName, $listener)
    {
        $this->dispatcher->addListener($eventName, $listener);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addSubscriber(EventSubscriberInterface $subscriber)
    {
        $this->dispatcher->addSubscriber($subscriber);

        return $this;
    }

    /**
     * Sets the logger for the Job.
     *
     * @param LoggerInterface $logger
     *
     * @return JobInterface
     *
     * @api
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * {@inheritdoc}
     */
    public function isRunning()
    {
        return in_array($this->status, array(static::STATUS_STARTED, static::STATUS_STOPPING), true);
    }

    /**
     * {@inheritdoc}
     */
    public function isStopping()
    {
        return static::STATUS_STOPPING === $this->status;
    }

    /**
     * {@inheritdoc}
     */
    public function isStarted()
    {
        return static::STATUS_STARTED === $this->status;
    }

    /**
     * {@inheritdoc}
     */
    public function getStatus()
    {
        return null === $this->status ? static::STATUS_STOPPED : $this->status;
    }

    /**
     * {@inheritdoc}
     */
    final public function run(JobDataInterface $data = null, $callback = null)
    {
        declare(ticks=1);
        $this->dispatcher->dispatch(TaskManagerEvents::START, new JobEvent($this));
        $this->setup();
        while (static::STATUS_STARTED === $this->status) {
            $this->doRunOrCleanup($data, $callback);
            $this->pause($this->getPauseDuration());
        }
        $this->dispatcher->dispatch(TaskManagerEvents::STOP, new JobEvent($this));

        return $this->cleanup();
    }

    /**
     * Runs the job implementation a single time.
     *
     * If a callback is provided, the it will be call with the job itself as
     * first argument an the return value of the doRun method as second
     * argument.
     *
     * @param JobDataInterface $data
     * @param null|callable    $callback A callback
     *
     * @return AbstractJob
     */
    final public function singleRun(JobDataInterface $data = null, $callback = null)
    {
        declare(ticks=1);

        $this->dispatcher->dispatch(TaskManagerEvents::START, new JobEvent($this));
        $this->setup();
        $this->doRunOrCleanup($data, $callback);
        $this->dispatcher->dispatch(TaskManagerEvents::STOP, new JobEvent($this));
        $this->cleanup();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        if ($this->isStarted()) {
            $this->status = static::STATUS_STOPPING;
        }

        return $this;
    }

    /**
     * Tick handler for the job.
     */
    public function tickHandler()
    {
        if (!$this->isRunning()) {
            return;
        }
        $this->dispatcher->dispatch(TaskManagerEvents::TICK, new JobEvent($this));
    }

    /**
     * Logs a message.
     *
     * @param string $method  A valid LoggerInterface method
     * @param string $message The message to be logged
     */
    protected function log($method, $message)
    {
        if (null !== $this->logger) {
            call_user_func(array($this->logger, $method), $message);
        }
    }

    /**
     * The time to pause between two iterations.
     *
     * @return float
     */
    protected function getPauseDuration()
    {
        return 0.005;
    }

    /**
     * The actual run method to implement.
     */
    abstract protected function doRun(JobDataInterface $data = null);

    /**
     * Pauses the execution of the job for the given duration.
     *
     * @param float $duration
     *
     * @return JobInterface
     */
    protected function pause($duration)
    {
        $time = microtime(true) + $duration;

        while (microtime(true) < $time) {
            if (static::STATUS_STARTED !== $this->status) {
                return $this;
            }
            // 50 ms is a good compromise between performance and reactivity
            usleep(50000);
        }

        return $this;
    }

    /**
     * Sets up the job and register tick handlers.
     *
     * @return JobInterface
     */
    private function setup()
    {
        $this->status = static::STATUS_STARTED;
        register_tick_function(array($this, 'tickHandler'), true);

        return $this;
    }

    /**
     * Cleanups a job.
     *
     * @return JobInterface
     */
    private function cleanup()
    {
        unregister_tick_function(array($this, 'tickHandler'));
        $this->status = static::STATUS_STOPPED;

        return $this;
    }

    /**
     * Does execute the doRun method. In case an exception is thrown, cleans up
     * and forward the exception.
     *
     * @param JobDataInterface $data     The data to pass to the doRun method.
     * @param null|callable    $callback A callback
     *
     * @return JobInterface
     *
     * @throws Exception The caught exception is forwarded in case it occured.
     */
    private function doRunOrCleanup(JobDataInterface $data = null, $callback = null)
    {
        try {
            call_user_func($this->createCallback($callback), $this, $this->doRun($data));
        } catch (\Exception $e) {
            $this->cleanup();
            $this->dispatcher->dispatch(TaskManagerEvents::EXCEPTION, new JobExceptionEvent($this, $e));
            throw $e;
        }

        return $this;
    }

    /**
     * Returns a callback given a callable or null argument.
     *
     * @param null|callable $callback
     *
     * @return callable
     */
    private function createCallback($callback)
    {
        if (is_callable($callback)) {
            return $callback;
        }

        return function () {};
    }
}
