<?php

/*
 * This file is part of Alchemy Task Manager
 *
 * (c) 2013 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\TaskManager\Event;

class TaskManagerEvents
{
    /**
     * This event is triggered when the job starts running.
     */
    const START = 'start';

    /**
     * This event is triggered on PHP tick.
     */
    const TICK = 'tick';

    /**
     * This event is triggered when the job stops running.
     */
    const STOP = 'stop';
}
