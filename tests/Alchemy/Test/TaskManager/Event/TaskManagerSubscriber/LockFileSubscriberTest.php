<?php

namespace Alchemy\Test\TaskManager\Event\TaskManagerSubscriber;

use Alchemy\TaskManager\Event\TaskManagerSubscriber\LockFileSubscriber;
use Alchemy\TaskManager\Event\TaskManagerEvent;
use Symfony\Component\Finder\Finder;

class LockFileSubscriberTest extends SubscriberTestCase
{
    /**
     * @dataProvider provideInvalidParams
     */
    public function testWithInvalidParams($directory, $message)
    {
        $this->setExpectedException('Alchemy\TaskManager\Exception\InvalidArgumentException', $message);
        new LockFileSubscriber(null, $directory);
    }

    public function provideInvalidParams()
    {
        return array(
            array('/path/to/no/dir', '/path/to/no/dir does not seem to be a directory.')
        );
    }

    public function testCreatesFileOnStartup()
    {
        $lockDir = __DIR__ . '/LockDir';

        $manager = $this->getMockBuilder('Alchemy\TaskManager\TaskManager')
                ->disableOriginalConstructor()
                ->getMock();

        $subscriber = $this->getSubscriber();
        $finder = Finder::create();
        $this->assertCount(0, $finder->files()->in($lockDir));
        $subscriber->onManagerStart(new TaskManagerEvent($manager));
        $finder = Finder::create();
        $this->assertCount(1, $finder->files()->in($lockDir));
        $subscriber->onManagerStop(new TaskManagerEvent($manager));
        $this->assertCount(0, $finder->files()->in($lockDir));
    }

    protected function getSubscriber()
    {
        $lockDir = __DIR__ . '/LockDir';
        if (!is_dir($lockDir)) {
            mkdir($lockDir);
        }

        $finder = Finder::create();
        $finder->useBestAdapter();

        $finder->files()->in($lockDir);

        foreach ($finder as $file) {
            unlink($file->getPathname());
        }

        return new LockFileSubscriber(null, $lockDir);
    }
}
