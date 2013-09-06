<?php

namespace Alchemy\Functional\TaskManager;

use Alchemy\Test\TaskManager\PhpProcess;

class JobTest extends FunctionalTestCase
{
    /**
     * @dataProvider provideVariousMemoryValues
     */
    public function testMaxMemory($max, $megPerSeconds, $expectedDuration)
    {
        $script = $this->getNonStoppingScript(1, ' $this->data .= str_repeat("x", '.$megPerSeconds.'*1024*1024);', '$job->addSubscriber(new \Alchemy\TaskManager\Event\Subscriber\MemoryLimitSubscriber('.$max.'*1024*1024));');
        $process1 = new PhpProcess($script);

        $start = microtime(true);
        $process1->run();

        $duration = microtime(true) - $start;

        $this->assertLessThan(0.30, abs($expectedDuration-$duration));
    }

    public function provideVariousMemoryValues()
    {
        return array(
            array(10, 10, 1),
            array(10, 5, 2),
            array(20, 20, 1),
            array(20, 10, 2),
        );
    }

    /**
     * @dataProvider provideVariousDurationValues
     */
    public function testMaxDuration($max)
    {
        $script = $this->getNonStoppingScript(0.1, '', '$job->addSubscriber(new \Alchemy\TaskManager\Event\Subscriber\DurationLimitSubscriber('.$max.'));');
        $process1 = new PhpProcess($script);
        
        $start = microtime(true);
        $process1->run();

        $duration = microtime(true) - $start;

        $this->assertLessThan(0.2, abs($max-$duration));
    }

    public function provideVariousDurationValues()
    {
        return array(array(0.3), array(0.5), array(0.7));
    }
}
