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

use Alchemy\TaskManager\Exception\InvalidArgumentException;
use Alchemy\TaskManager\Exception\LogicException;
use Alchemy\TaskManager\Event\JobEvent;
use Alchemy\TaskManager\Event\TaskManagerEvents;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

abstract class AbstractJob implements JobInterface
{
    /** @var string */
    private $id;
    /** @var null|float */
    private $lastSignalTime = null;
    /** @var null|float */
    private $startTime = null;
    /** @var null|float */
    private $signalPeriod = 0.5;
    /** @var integer */
    private $mode = 0;
    /** @var null|string */
    private $status;
    /** @var null|LoggerInterface */
    private $logger;
    /** @var null|string */
    private $lockDir;
    /** @var LockFile */
    private $lockFile;
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
     * {@inheritdoc}
     */
    public function getLockDirectory()
    {
        if (null === $this->lockDir) {
            return sys_get_temp_dir();
        }

        return $this->lockDir;
    }

    /**
     * {@inheritdoc}
     */
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
     * {@inheritdoc}
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function setId($id)
    {
        $this->id = (string) $id;

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
    public function enableStopMode($mode)
    {
        $this->mode |= $this->validateMode($mode);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function disableStopMode($mode)
    {
        $this->mode = $this->mode & ~$this->validateMode($mode);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function isStopMode($mode)
    {
        return (boolean) ($this->mode & $this->validateMode($mode));
    }

    /**
     * {@inheritdoc}
     */
    public function getSignalPeriod()
    {
        return $this->signalPeriod;
    }

    /**
     * {@inheritdoc}
     */
    public function setSignalPeriod($period)
    {
        // use 3x the step used for pause
        if (0.15 > $period) {
            throw new InvalidArgumentException('Signal period should be greater than 0.15 s.');
        }

        $this->enableStopMode(JobInterface::MODE_STOP_UNLESS_SIGNAL);
        $this->signalPeriod = (float) $period;

        return $this;
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
     * Signal handler for the job.
     *
     * @param integer $signal
     */
    public function signalHandler($signal)
    {
        switch ($signal) {
            case SIGCONT:
                $this->lastSignalTime = microtime(true);
                break;
            case SIGTERM:
                $this->log('info', 'Caught SIGTERM signal, stopping');
                $this->stop();
                break;
            case SIGINT:
                $this->log('info', 'Caught SIGINT signal, stopping');
                $this->stop();
                break;
        }
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
        $this->checkSignals();
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
     * Sets up the job, add locks and reister tick handlers.
     *
     * @return JobInterface
     */
    private function setup()
    {
        $this->lockFile = new LockFile($this->getLockFilePath());
        $this->lockFile->lock();

        $this->status = static::STATUS_STARTED;
        $this->startTime = microtime(true);

        register_tick_function(array($this, 'tickHandler'), true);
        pcntl_signal(SIGCONT, array($this, 'signalHandler'));
        pcntl_signal(SIGTERM, array($this, 'signalHandler'));
        pcntl_signal(SIGINT, array($this, 'signalHandler'));

        return $this;
    }

    /**
     * Cleanups a job.
     *
     * Removes locks and handlers.
     *
     * @return JobInterface
     */
    private function cleanup()
    {
        if (null !== $this->lockFile) {
            $this->lockFile->unlock();
        }
        unregister_tick_function(array($this, 'tickHandler'));
        pcntl_signal(SIGINT, function () {});
        pcntl_signal(SIGCONT, function () {});
        pcntl_signal(SIGTERM, function () {});
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

    /**
     * In case the mode MODE_STOP_UNLESS_SIGNAL is enabled, checks for the
     * latest received signal. Stops the job if no signal received in the
     * latest period.
     */
    private function checkSignals()
    {
        if (!$this->isStopMode(static::MODE_STOP_UNLESS_SIGNAL)) {
            return;
        }

        if (null === $this->lastSignalTime) {
            if ((microtime(true) - $this->startTime) > $this->signalPeriod) {
                $this->log('debug', sprintf('No signal received since start-time (max period is %s s.), stopping.', $this->signalPeriod));
                $this->stop();
            }
        } elseif ((microtime(true) - $this->lastSignalTime) > $this->signalPeriod) {
            $this->log('debug', sprintf('No signal received since %s, (max period is %s s.), stopping.', (microtime(true) - $this->lastSignalTime), $this->signalPeriod));
            $this->stop();
        }
    }

    /**
     * Return the file path to the lock file for this job.
     *
     * @return string
     *
     * @throws LogicException In case no Id has been set.
     */
    private function getLockFilePath()
    {
        if (null === $this->getId()) {
            throw new LogicException('An ID must be set to the JOB');
        }

        return $this->getLockDirectory() . '/task_' . $this->getID() . '.lock';
    }

    /**
     * Validates a stop mode.
     *
     * @param integer $mode One of the MODE_STOP_* constant.
     *
     * @return integer The validated mode
     *
     * @throws InvalidArgumentException In case the mode is invalid
     */
    private function validateMode($mode)
    {
        if (!in_array($mode, array(static::MODE_STOP_UNLESS_SIGNAL), true)) {
            throw new InvalidArgumentException('Invalid mode value.');
        }

        return $mode;
    }
}
