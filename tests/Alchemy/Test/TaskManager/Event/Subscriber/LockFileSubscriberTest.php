<?php

namespace Alchemy\Test\TaskManager\Event\Subscriber;

use Alchemy\TaskManager\Event\Subscriber\LockFileSubscriber;
use Alchemy\TaskManager\Event\JobEvent;
use Symfony\Component\Finder\Finder;

class LockFileSubscriberTest extends SubscriberTestCase
{
    /**
     * @dataProvider provideInvalidParams
     */
    public function testWithInvalidParams($id, $directory, $message)
    {
        $this->setExpectedException('Alchemy\TaskManager\Exception\InvalidArgumentException', $message);
        new LockFileSubscriber($id, null, $directory);
    }

    public function provideInvalidParams()
    {
        return array(
            array('', __DIR__, 'The id can not be empty.'),
            array(null, __DIR__, 'The id can not be empty.'),
            array('id', '/path/to/no/dir', '/path/to/no/dir does not seem to be a directory.')
        );
    }

    public function testCreatesFileOnStartup()
    {
        $lockDir = __DIR__ . '/LockDir';

        $job = $this->getMock('Alchemy\TaskManager\JobInterface');

        $subscriber = $this->getSubscriber();
        $finder = Finder::create();
        $this->assertCount(0, $finder->files()->in($lockDir));
        $subscriber->onJobStart(new JobEvent($job));
        $finder = Finder::create();
        $this->assertCount(1, $finder->files()->in($lockDir));
        $subscriber->onJobStop(new JobEvent($job));
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

        return new LockFileSubscriber('id', null, $lockDir);
    }
}
