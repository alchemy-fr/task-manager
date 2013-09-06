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
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * A Job Interface
 */
interface JobInterface extends LoggerAwareInterface
{
    const MODE_STOP_UNLESS_SIGNAL = 1;

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
     * Sets the period to wait for a SIGCONT signal otherwise the job
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
     * Returns the period to wait for a SIGCONT signal otherwise the job
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
     * Adds a listener to the job.
     *
     * @param string   $eventName
     * @param callable $listener
     *
     * @return JobInterface
     */
    public function addListener($eventName, $listener);

    /**
     * Adds an event subscriber to the job.
     *
     * @param EventSubscriberInterface $subscriber
     *
     * @return JobInterface
     */
    public function addSubscriber(EventSubscriberInterface $subscriber);

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
     * If a callback is provided, the it will be call with the job itself as
     * first argument an the return value of the doRun method as second
     * argument.
     *
     * @param JobDataInterface $data
     *
     * @return JobInterface
     *
     * @api
     */
    public function run(JobDataInterface $data = null, $callback = null);

    /**
     * Runs the job implementation a single time.
     *
     * @param JobDataInterface $data
     *
     * @return JobInterface
     */
    public function singleRun(JobDataInterface $data = null);

    /**
     * Checks if the job is running.
     *
     * Please note that a stopping job is considered running. To check if the
     * status is exactly in the started status, use the isStarted method.
     *
     * @return Boolean
     *
     * @api
     */
    public function isRunning();

    /**
     * Checks if the job is stopping.
     *
     * @return Boolean
     *
     * @api
     */
    public function isStopping();

    /**
     * Checks if the job is started.
     *
     * @return Boolean
     *
     * @api
     */
    public function isStarted();

    /**
     * Gets the current status of the job, one of the STATUS_* constant.
     *
     * @return string
     *
     * @api
     */
    public function getStatus();
}
