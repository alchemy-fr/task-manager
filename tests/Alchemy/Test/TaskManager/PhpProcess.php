<?php

namespace Alchemy\Test\TaskManager;

use Symfony\Component\Process\PhpProcess as SfPhpProcess;

class PhpProcess extends SfPhpProcess
{
    public function setCommandLine($commandline)
    {
        return parent::setCommandLine('exec ' . $commandline);
    }
}
