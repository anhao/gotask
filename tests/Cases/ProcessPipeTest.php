<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf/GoTask.
 *
 * @link     https://www.github.com/hyperf/gotask
 * @document  https://www.github.com/hyperf/gotask
 * @contact  guxi99@gmail.com
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace HyperfTest\Cases;

use Hyperf\GoTask\IPC\PipeIPCSender;
use Hyperf\GoTask\PipeGoTask;
use Hyperf\GoTask\Relay\RelayInterface;
use Hyperf\Utils\WaitGroup;
use Spiral\Goridge\Exceptions\ServiceException;
use Swoole\Process;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class ProcessPipeTest extends AbstractTestCase
{
    /**
     * @var Process
     */
    private $p;

    public function setUp(): void
    {
        parent::setUp(); // TODO: Change the autogenerated stub
        $this->p = new Process(function (Process $process) {
            $process->exec(__DIR__ . '/../../app', ['-listen-on-pipe']);
        }, true);
        $this->p->start();
        sleep(1);
    }

    public function tearDown(): void
    {
        Process::kill($this->p->pid);
    }

    public function testOnCoroutine()
    {
        \Swoole\Coroutine\run(function () {
            $task = new PipeIPCSender($this->p);
            $this->baseExample($task);
        });
    }

    public function testConcurrently()
    {
        \Swoole\Coroutine\run(function () {
            sleep(1);
            $task = make(PipeGoTask::class, [
                'process' => $this->p,
            ]);
            $wg = new WaitGroup();
            $wg->add();
            go(function () use ($wg, $task) {
                $this->baseExample($task);
                $wg->done();
            });
            $wg->add();
            go(function () use ($wg, $task) {
                $this->baseExample($task);
                $wg->done();
            });
            $wg->wait();
        });
    }

    public function baseExample($task)
    {
        $this->assertEquals(
            'Hello, Hyperf!',
            $task->call('App.HelloString', 'Hyperf')
        );
        $this->assertEquals(
            ['hello' => ['jack', 'jill']],
            $task->call('App.HelloInterface', ['jack', 'jill'])
        );
        $this->assertEquals(
            ['hello' => [
                'firstName' => 'LeBron',
                'lastName' => 'James',
                'id' => 23,
            ]],
            $task->call('App.HelloStruct', [
                'firstName' => 'LeBron',
                'lastName' => 'James',
                'id' => 23,
            ])
        );

        $this->assertEquals(
            'My Bytes',
            $task->call('App.HelloBytes', base64_encode('My Bytes'), RelayInterface::PAYLOAD_RAW)
        );
        try {
            $task->call('App.HelloError', 'Hyperf');
        } catch (Throwable $e) {
            $this->assertInstanceOf(ServiceException::class, $e);
        }
    }
}
