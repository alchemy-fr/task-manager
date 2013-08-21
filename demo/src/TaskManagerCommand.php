<?php

namespace Alchemy\TaskManager\Demo;

use Alchemy\TaskManager\TaskManager;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TaskManagerCommand extends Command
{
    /** @var TaskManager */
    private $manager;

    public function __construct()
    {
        parent::__construct('console:run-task-manager');

        $this->addOption('host', null, InputOption::VALUE_REQUIRED, 'The host to bind to.', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'The port tot bind to.', '6660');

        $socket = new \ZMQSocket(new \ZMQContext(), \ZMQ::SOCKET_REP);

        $logger = new Logger('test');
        $logger->pushHandler(new StreamHandler('php://stdout'));

        $this->manager = new TaskManager($socket, $logger, new TaskList());
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        declare(ticks=1);
        pcntl_signal(SIGINT, array($this, 'signalHandler'));
        pcntl_signal(SIGTERM, array($this, 'signalHandler'));

        $this->manager->start($input->getOption('host'), $input->getOption('port'));
    }

    public function signalHandler($signal)
    {
        switch ($signal) {
            case SIGTERM:
            case SIGINT:
                $this->manager->stop();
                break;
        }
    }
}
