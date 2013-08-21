<?php

namespace Alchemy\Test\TaskManager;

use Alchemy\TaskManager\TaskInterface;
use Alchemy\TaskManager\TaskListInterface;
use Alchemy\TaskManager\TaskManager;

class TaskManagerTest extends \PHPUnit_Framework_TestCase
{
    public function testThatItRunsWithoutAnyProcesses()
    {
        $socket = new \ZMQSocket(new \ZMQContext(), \ZMQ::SOCKET_REP, 'my socket');
        $taskList = $this->getMock('Alchemy\TaskManager\TaskListInterface');
        $taskList->expects($this->any())
            ->method('refresh')
            ->will($this->returnValue(array()));
        $manager = new TaskManager($socket, $this->createLoggerMock(), $taskList);
        declare(ticks=1);
        pcntl_alarm(1);
        pcntl_signal(SIGALRM, function () use ($manager) { $manager->stop(); });
        $start = microtime(true);
        $manager->start();
        $this->assertGreaterThanOrEqual(1, microtime(true) - $start);
    }

    public function testThatItRunsProcessesThenStop()
    {
        $testfile = __DIR__ . '/testfile';
        if (is_file($testfile)) {
            unlink($testfile);
        }
        touch($testfile);

        $serverScript = '<?php
            require "'.__DIR__.'/../../../../vendor/autoload.php";
            '
            .$this->getTaskImplementation().$this->getTaskListImplementation()
            .'
            use Alchemy\TaskManager\TaskManager;
            use Symfony\Component\Process\Process;

            $socket = new \ZMQSocket(new \ZMQContext(), \ZMQ::SOCKET_REP);
            $taskList = new TaskList(array(new Task("task 1", new Process("echo \"hello\" >> '.$testfile.'"), 3)));
            $logger = new \Monolog\Logger("test");
            $logger->pushHandler(new \Monolog\Handler\StreamHandler("php://stdout"));
            $manager = new TaskManager($socket, $logger, $taskList);
            $manager->start();
        ';

        $process = new \Symfony\Component\Process\PhpProcess($serverScript);
        $process->start();
        usleep(700000);
        $process->stop();
        $data = file_get_contents($testfile);
        unlink($testfile);
        $this->assertEquals("hello\nhello\nhello\n", $data);
    }

    public function testThatItRespondstoPingCommand()
    {
        $serverScript = '<?php
            require "'.__DIR__.'/../../../../vendor/autoload.php";
            '
            .$this->getTaskImplementation().$this->getTaskListImplementation()
            .'
            use Alchemy\TaskManager\TaskManager;

            $socket = new \ZMQSocket(new \ZMQContext(), \ZMQ::SOCKET_REP);
            $taskList = new TaskList(array());
            $logger = new \Monolog\Logger("test");
            $logger->pushHandler(new \Monolog\Handler\StreamHandler("php://stdout"));
            $manager = new TaskManager($socket, $logger, $taskList);
            $manager->start();
        ';

        $server = new \Symfony\Component\Process\PhpProcess($serverScript);
        $server->start();

        $process = new \Symfony\Component\Process\PhpProcess('<?php
            require "'.__DIR__.'/../../../../vendor/autoload.php";
            use Alchemy\TaskManager\TaskManager;

            $client = new \ZMQSocket(new \ZMQContext(), \ZMQ::SOCKET_REQ);
            $client->connect("tcp://127.0.0.1:6660");
            $client->send(TaskManager::MESSAGE_PING);
            $message = $client->recv();
            echo $message;
        ');
        $process->run();
        $server->stop();
        $this->assertEquals('PONG', $process->getOutput());
    }

    public function testMultipleStartsAndStops()
    {
        $serverScript = '<?php
            require "'.__DIR__.'/../../../../vendor/autoload.php";
            '
            .$this->getTaskImplementation().$this->getTaskListImplementation()
            .'
            use Alchemy\TaskManager\TaskManager;

            $socket = new \ZMQSocket(new \ZMQContext(), \ZMQ::SOCKET_REP);
            $taskList = new TaskList(array());
            $logger = new \Monolog\Logger("test");
            $logger->pushHandler(new \Monolog\Handler\StreamHandler("php://stdout"));
            $manager = new TaskManager($socket, $logger, $taskList);
            $manager->start();
        ';

        $server = new \Symfony\Component\Process\PhpProcess($serverScript);
        $this->assertFalse($server->isRunning());
        $server->start();
        $this->assertTrue($server->isRunning());
        $server->stop();
        $this->assertFalse($server->isRunning());
        $server->start();
        $this->assertTrue($server->isRunning());
        $server->stop();
        $this->assertFalse($server->isRunning());
    }

    public function testThatTwoTaskManagerCanNotRunOnSamePortAndHostAtTheSameTime()
    {
        $socket = new \ZMQSocket(new \ZMQContext(), \ZMQ::SOCKET_REP);
        $socket->bind('tcp://127.0.0.1:6660');

        $socket2 = new \ZMQSocket(new \ZMQContext(), \ZMQ::SOCKET_REP);
        $taskList = $this->getMock('Alchemy\TaskManager\TaskListInterface');
        $manager = new TaskManager($socket2, $this->createLoggerMock(), $taskList);
        $this->setExpectedException('Alchemy\TaskManager\Exception\RuntimeException', 'Unable to bind ZMQ socket');
        $manager->start();
    }

    private function createLoggerMock()
    {
        return $this->getMock('Psr\Log\LoggerInterface');
    }

    private function getTaskListImplementation()
    {
        return '

use Alchemy\TaskManager\TaskListInterface;

class TaskList implements TaskListInterface
{
    private $tasks;

    public function __construct(array $tasks)
    {
        $this->tasks = $tasks;
    }

    public function refresh()
    {
        return $this->tasks;
    }
}';
    }

    private function getTaskImplementation()
    {
        return '
use Alchemy\TaskManager\TaskInterface;

class Task implements TaskInterface
{
    private $name;
    private $process;
    private $iterations;

    public function __construct($name, $process, $iterations)
    {
        $this->name = $name;
        $this->process = $process;
        $this->iterations = $iterations;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getIterations()
    {
        return $this->iterations;
    }

    public function createProcess()
    {
        return clone $this->process;
    }
}
';
    }
}

class TaskList implements TaskListInterface
{
    private $tasks;

    public function __construct(array $tasks)
    {
        $this->tasks = $tasks;
    }

    public function refresh()
    {
        return $this->tasks;
    }
}

class Task implements TaskInterface
{
    private $name;
    private $process;
    private $iterations;

    public function __construct($name, $process, $iterations)
    {
        $this->name = $name;
        $this->process = $process;
        $this->iterations = $iterations;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getIterations()
    {
        return $this->iterations;
    }

    public function createProcess()
    {
        return clone $this->process;
    }
}