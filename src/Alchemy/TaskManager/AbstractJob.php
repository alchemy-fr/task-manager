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
use Psr\Log\LoggerInterface;

abstract class AbstractJob implements JobInterface
{
    /** @var string */
    private $id;
    /** @var null|float */
    private $lastSignalTime = null;
    /** @var null|float */
    private $startTime = null;
    /** @var null|float */
    private $maxDuration;
    /** @var null|float */
    private $signalPeriod;
    /** @var null|integer */
    private $maxMemory;
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

    public function __destruct()
    {
        if ($this->isRunning() && null !== $this->lockFile) {
            $this->lockFile->unlock();
        }
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
    public function getMaxDuration()
    {
        return $this->maxDuration;
    }

    /**
     * {@inheritdoc}
     */
    public function setMaxDuration($duration)
    {
        if (0 >= $duration) {
            throw new InvalidArgumentException('Maximum duration should be a positive value.');
        }

        $this->maxDuration = (float) $duration;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getMaxMemory()
    {
        return $this->maxMemory;
    }

    /**
     * {@inheritdoc}
     */
    public function setMaxMemory($memory)
    {
        if (0 >= $memory) {
            throw new InvalidArgumentException('Maximum memory should be a positive value.');
        }

        $this->maxMemory = (integer) $memory;

        return $this;
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
        if (0 >= $period) {
            throw new InvalidArgumentException('Signal period should be a positive value.');
        }

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
    public function getStatus()
    {
        return null === $this->status ? static::STATUS_STOPPED : $this->status;
    }

    /**
     * {@inheritdoc}
     */
    final public function run()
    {
        $this->lockFile = new LockFile($this->getLockFilePath());
        $this->lockFile->lock();

        $this->status = static::STATUS_STARTED;
        $this->startTime = microtime(true);

        declare(ticks=1);
        register_tick_function(array($this, 'tickHandler'), true);
        pcntl_signal(SIGUSR1, array($this, 'signalHandler'));
        pcntl_signal(SIGTERM, array($this, 'signalHandler'));
        pcntl_signal(SIGINT, array($this, 'signalHandler'));

        while (static::STATUS_STARTED === $this->status) {
            $this->doRun();
            $this->pause($this->getPauseDuration());
        }

        $this->lockFile->unlock();
        $this->status = static::STATUS_STOPPED;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        $this->status = static::STATUS_STOPPING;

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
            case SIGUSR1:
                $this->lastSignalTime = microtime(true);
                break;
            case SIGTERM:
            case SIGINT:
                $this->stop();
                break;
        }
    }

    /**
     * Tick handler for the job.
     */
    public function tickHandler()
    {
        $this->checkDuration();
        $this->checkSignals();
        $this->checkMemory();
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
        return 0;
    }

    /**
     * The actual run method to implement.
     */
    abstract protected function doRun();

    /**
     * Pauses the execution of the job for the given duration.
     *
     * @param float $duration
     *
     * @return JobInterface
     */
    private function pause($duration)
    {
        $time = microtime(true) + $duration;

        while (microtime(true) < $time) {
            if (static::STATUS_STARTED !== $this->status) {
                return $this;
            }
            usleep(1000);
        }

        return $this;
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
                $this->stop();
            }
        } elseif ((microtime(true) - $this->lastSignalTime) > $this->signalPeriod) {
            $this->stop();
        }
    }

    /**
     * In case the mode MODE_STOP_ON_MEMORY is enabled, checks for the
     * amount of memory used. Stops the job if the more thand the maximum
     * allowed amount of memory is used.
     */
    private function checkMemory()
    {
        if (!$this->isStopMode(static::MODE_STOP_ON_MEMORY) || null === $this->maxMemory) {
            return;
        }

        if (memory_get_usage() > $this->maxMemory) {
            $this->stop();
        }
    }

    /**
     * In case the mode MODE_STOP_ON_DURATION is enabled, checks for the
     * current duration of the job. Stops the job if its current duration is
     * longer than the maximum allowed duration.
     */
    private function checkDuration()
    {
        if (!$this->isStopMode(static::MODE_STOP_ON_DURATION) || null === $this->maxDuration) {
            return;
        }

        if ((microtime(true) - $this->startTime) > $this->maxDuration) {
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
        if (!in_array($mode, array(static::MODE_STOP_ON_DURATION, static::MODE_STOP_ON_MEMORY, static::MODE_STOP_UNLESS_SIGNAL), true)) {
            throw new InvalidArgumentException('Invalid mode value.');
        }

        return $mode;
    }
}