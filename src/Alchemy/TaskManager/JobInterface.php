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

use Psr\Log\LoggerAwareInterface;

/**
 * A Job Interface
 */
interface JobInterface extends LoggerAwareInterface
{
    const MODE_STOP_UNLESS_SIGNAL = 1;
    const MODE_STOP_ON_MEMORY = 2;
    const MODE_STOP_ON_DURATION = 4;

    const STATUS_STARTED = 'started';
    const STATUS_STOPPING = 'stopping';
    const STATUS_STOPPED = 'stopped';

    /**
     * Gets the unique Id of the Job.
     *
     * This unique id is used to prevent running the same job concurrently.
     *
     * @return string
     *
     * @api
     */
    public function getId();

    /**
     * Sets the unique Id of the Job.
     *
     * This unique id is used to prevent running the same job concurrently.
     *
     * @param string $id
     *
     * @return JobInterface
     *
     * @api
     */
    public function setId($id);

    /**
     * Sets the maximum duration of a job, if the MODE_STOP_ON_DURATION is
     * enabled.
     *
     * @param float $duration
     *
     * @return JobInterface
     *
     * @throws InvalidArgumentException
     *
     * @api
     */
    public function setMaxDuration($duration);

    /**
     * Returns the maximum duration of a the Job, if the MODE_STOP_ON_DURATION
     * is enabled.
     *
     * @return float
     *
     * @api
     */
    public function getMaxDuration();

    /**
     * Sets the maximum memory the job can use before it would be stopped, if
     * the MODE_STOP_ON_MEMORY mode is enabled.
     *
     * @param integer $memory
     *
     * @return JobInterface
     *
     * @throws InvalidArgumentException
     *
     * @api
     */
    public function setMaxMemory($memory);

    /**
     * Returns the maximum memory the job can use before it would be stopped, if
     * the MODE_STOP_ON_MEMORY mode is enabled.
     *
     * @return integer
     *
     * @api
     */
    public function getMaxMemory();

    /**
     * Sets the period to wait for a SIGUSR1 signal otherwise the job
     * would be stopped, in case the MODE_STOP_UNLESS_SIGNAL mode is enabled.
     *
     * @param float $period
     *
     * @return JobInterface
     *
     * @throws InvalidArgumentException
     *
     * @api
     */
    public function setSignalPeriod($period);

    /**
     * Returns the period to wait for a SIGUSR1 signal otherwise the job
     * would be stopped, in case the MODE_STOP_UNLESS_SIGNAL mode is enabled.
     *
     * @return float
     *
     * @api
     */
    public function getSignalPeriod();

    /**
     * Enables a stop mode.
     *
     * @param integer $mode One of the MODE_STOP_* constant
     *
     * @return JobInterface
     *
     * @api
     */
    public function enableStopMode($mode);

    /**
     * Disables a stop mode.
     *
     * @param integer $mode One of the MODE_STOP_* constant
     *
     * @return JobInterface
     *
     * @api
     */
    public function disableStopMode($mode);

    /**
     * Checks if a stop mode is enabled.
     *
     * @param integer $mode One of the MODE_STOP_* constant
     *
     * @return Boolean
     *
     * @api
     */
    public function isStopMode($mode);

    /**
     * Gets the lock directory.
     *
     * The lock directory is used to store lock file which prevent running
     * a job with the same Id multiple times.
     * If no lock directory set, the system temporary directory is returned.
     *
     * @return string
     *
     * @api
     */
    public function getLockDirectory();

    /**
     * Sets the lock directory.
     *
     * The lock directory is used to store lock file which prevent running
     * a job with the same Id multiple times.
     *
     * @param string $directory
     *
     * @return JobInterface
     *
     * @throws InvalidArgumentException In case the directory does not exist or is not writeable.
     *
     * @api
     */
    public function setLockDirectory($directory);

    /**
     * Stops the job.
     *
     * @return JobInterface
     *
     * @api
     */
    public function stop();

    /**
     * Runs the job.
     *
     * @return JobInterface
     *
     * @api
     */
    public function run();

    /**
     * Checks if the job is running.
     *
     * @return Boolean
     *
     * @api
     */
    public function isRunning();

    /**
     * Gets the current status of the job, one of the STATUS_* constant.
     *
     * @return string
     *
     * @api
     */
    public function getStatus();
}
