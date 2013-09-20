<?php

namespace Alchemy\Functional\TaskManager;

use Symfony\Component\Finder\Finder;

class FunctionalTestCase extends \PHPUnit_Framework_TestCase
{
    protected $lockDir;

    public function setUp()
    {
        $this->lockDir = __DIR__ . '/LockDir';
        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir);
        }

        $finder = Finder::create();
        $finder->useBestAdapter();

        $finder->files()->in($this->lockDir);

        foreach ($finder as $file) {
            unlink($file->getPathname());
        }
    }

    protected function getNonStoppingScript($time, $extra, $conf)
    {
        return '<?php
        require "'.__DIR__.'/../../../../vendor/autoload.php";

        use Alchemy\TaskManager\JobDataInterface;

        declare(ticks=1);

        class Job extends Alchemy\TaskManager\AbstractJob
        {
            protected function doRun(JobDataInterface $data = null)
            {
                '.$extra.'
                usleep('.($time*10).'*100000);
            }
        }

        $job = new Job();
        '.$conf.'
        assert($job === $job->run());
        ';
    }

    protected function getSelfStoppingScript()
    {
        return '<?php
        require "'.__DIR__.'/../../../../vendor/autoload.php";

        use Alchemy\TaskManager\JobDataInterface;

        declare(ticks=1);

        class Job extends Alchemy\TaskManager\AbstractJob
        {
            protected function doRun(JobDataInterface $data = null)
            {
                $n = 0;
                while ($n < 60 && $this->getStatus() === static::STATUS_STARTED) {
                    usleep(10000);
                    $n++;
                }
                $this->stop();
            }
        }

        $job = new Job();
        $job->addSubscriber(new \Alchemy\TaskManager\Event\JobSubscriber\StopSignalSubscriber(Neutron\SignalHandler\SignalHandler::getInstance()));
        $job->addSubscriber(new \Alchemy\TaskManager\Event\JobSubscriber\LockFileSubscriber("id", null, "'.$this->lockDir.'"));
        $job->run();
        ';
    }
}
