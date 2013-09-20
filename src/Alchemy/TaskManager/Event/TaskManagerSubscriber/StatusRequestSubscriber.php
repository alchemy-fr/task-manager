<?php

/*
 * This file is part of Alchemy Task Manager
 *
 * (c) 2013 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\TaskManager\Event\TaskManagerSubscriber;

use Alchemy\TaskManager\Event\TaskManagerRequestEvent;
use Alchemy\TaskManager\Event\TaskManagerEvents;
use Alchemy\TaskManager\TaskManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Process\ProcessInterface;

/**
 * Writes a lock file to prevent running the task manager multiple times concurrently.
 */
class StatusRequestSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return array(
            TaskManagerEvents::MANAGER_REQUEST => 'onManagerRequest',
        );
    }

    public function onManagerRequest(TaskManagerRequestEvent $event)
    {
        if (TaskManager::MESSAGE_STATE !== $event->getRequest()) {
            return;
        }

        $data = array();
        foreach ($event->getManager()->getProcessManager()->getManagedProcesses() as $name => $process) {
            $data[$name] = array(
                'status' => $process->getStatus(),
                'pid'    => $process->getManagedProcess() instanceof ProcessInterface ? $process->getManagedProcess()->getPid() : null,
            );
        }

        $event->setResponse($data);
    }
}
