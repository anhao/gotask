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

namespace Hyperf\GoTask\Listener;

use Hyperf\Context\ApplicationContext;
use Hyperf\ExceptionHandler\Formatter\FormatterInterface;
use Hyperf\GoTask\IPC\IPCSenderInterface;
use Hyperf\GoTask\Relay\ConnectionRelay;
use Spiral\Goridge\RPC\Exception\ServiceException;
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;
use Swoole\Exception;
use Throwable;

class SocketIPCReceiver
{
    /**
     * @var string
     */
    private $address;

    /**
     * @var Server
     */
    private $server;

    /**
     * @var int
     */
    private $port;

    /**
     * @var bool
     */
    private $quit;

    public function __construct(string $address = '127.0.0.1:6001')
    {
        $split = explode(':', $address, 2);
        if (count($split) === 1) {
            $this->address = 'unix:' . $address;
            $this->port = 0;
        } else {
            $this->address = $split[0];
            $this->port = (int) $split[1];
        }
    }

    /**
     * @throws Exception
     */
    public function start(): bool
    {
        if ($this->isStarted()) {
            return true;
        }
        $this->server = new Server($this->address, $this->port, false, true);
        $this->quit = false;
        $this->server->handle(function (Connection $conn) {
            $relay = new ConnectionRelay($conn);
            while ($this->quit !== true) {
                throw new \Exception('TODO');
            }
        });
        $this->server->start();
        return true;
    }

    public function close()
    {
        if ($this->server !== null) {
            $this->quit = true;
            $this->server->shutdown();
        }
        $this->server = null;
    }

    protected function dispatch($method, $payload)
    {
        [$class, $handler] = explode('::', $method);
        if (ApplicationContext::hasContainer()) {
            $container = ApplicationContext::getContainer();
            $instance = $container->get($class);
        } else {
            $instance = new $class();
        }
        return $instance->{$handler}($payload);
    }

    protected function isStarted()
    {
        return $this->server !== null;
    }

    /**
     * Handle response body.
     *
     * @param string $body
     *
     * @return mixed
     * @throws ServiceException
     */
    protected function handleBody($body, int $flags)
    {
        if ($flags & IPCSenderInterface::PAYLOAD_ERROR && $flags & IPCSenderInterface::PAYLOAD_RAW) {
            throw new ServiceException("error '{$body}' on '{$this->server}'");
        }

        if ($flags & IPCSenderInterface::PAYLOAD_RAW) {
            return $body;
        }

        return json_decode($body, true);
    }

    private function formatError(Throwable $error)
    {
        $simpleFormat = $error->getMessage() . ':' . $error->getTraceAsString();
        if (! ApplicationContext::hasContainer()) {
            return $simpleFormat;
        }
        $container = ApplicationContext::getContainer();
        if (! $container->has(FormatterInterface::class)) {
            return $simpleFormat;
        }
        return $container->get(FormatterInterface::class)->format($error);
    }
}
