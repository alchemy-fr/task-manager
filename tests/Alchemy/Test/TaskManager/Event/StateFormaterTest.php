<?php

namespace Alchemy\Test\TaskManager\Event;

use Alchemy\TaskManager\Event\StateFormater;
use Alchemy\Test\TaskManager\TestCase;

class StateFormaterTest extends TestCase
{
    /**
     * @dataProvider provideProcessesAndStates
     */
    public function testToArray($processes, $state)
    {
        $formater = new StateFormater();
        $this->assertEquals($state, $formater->toArray($processes));
    }

    public function provideProcessesAndStates()
    {
        $pid = getmypid();

        $process1 = $this->getMockBuilder('Symfony\Component\Process\Process')
            ->disableOriginalConstructor()
            ->getMock();
        $process1->expects($this->once())
                ->method('getPid')
                ->will($this->returnValue(1234));
        $process2 = $this->getMockBuilder('Symfony\Component\Process\Process')
            ->disableOriginalConstructor()
            ->getMock();

        $managed1 = $this->getMockBuilder('Neutron\ProcessManager\ManagedProcess')
                ->disableOriginalConstructor()
                ->disableOriginalClone()
                ->getMock();
        $managed1->expects($this->any())
                ->method('getManagedProcess')
                ->will($this->returnValue($process1));
        $managed1->expects($this->once())
                ->method('getStatus')
                ->will($this->returnValue('laughing'));

        $managed2 = $this->getMockBuilder('Neutron\ProcessManager\ManagedProcess')
                ->disableOriginalConstructor()
                ->disableOriginalClone()
                ->getMock();
        $managed2->expects($this->any())
                ->method('getManagedProcess')
                ->will($this->returnValue($process2));
        $managed2->expects($this->once())
                ->method('getStatus')
                ->will($this->returnValue('started'));

        $state = array(
            'manager' => array(
                'process-id' => $pid,
            ),
            'jobs' => array(
                'task-0' => array(
                    'status' => 'laughing',
                    'process-id' => 1234
                ),
                'task-1' => array(
                    'status' => 'started',
                    'process-id' => null
                ),
            )
        );

        return array(
            array(array('task-0' => $managed1, 'task-1' => $managed2), $state)
        );
    }
}
