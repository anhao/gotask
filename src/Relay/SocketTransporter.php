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

namespace Hyperf\GoTask\Relay;

use Spiral\Goridge\Exception\HeaderException;
use Spiral\Goridge\Exception\RelayException;
use Spiral\Goridge\Frame;

use function socket_last_error;
use function socket_recv;
use function socket_strerror;
use function sprintf;

trait SocketTransporter
{
    /**
     * Destruct connection and disconnect.
     */
    public function __destruct()
    {
        if ($this->isConnected()) {
            $this->close();
        }
    }

    public function send(Frame $frame): void
    {
        $this->connect();
        $body = Frame::packFrame($frame);
        $this->socket->send($body);
    }

    /**
     * @throws RelayException
     * @psalm-suppress PossiblyNullArgument Reason: Using the "connect()" method guarantees
     *                                      the existence of the socket.
     */
    public function waitFrame(): Frame
    {
        $this->connect();
        $header = $this->socket->recv(12);
        $headerLength = strlen(strval($header));

        if ($headerLength !== 12) {
            $errCode = swoole_last_error();
            $errMsg = socket_strerror($errCode);
            throw new HeaderException(sprintf('Unable to read frame header: %s', $errMsg));
        }

        $parts = Frame::readHeader($header);

        // total payload length
        $payload = '';
        $length = $parts[1] * 4 + $parts[2];

        while ($length > 0) {
            $buffer = $this->socket->recv($length);
            $bufferLength = strlen(strval($buffer));

            /**
             * Suppress "buffer === null" assertion, because buffer can contain
             * NULL in case of socket_recv function error.
             *
             * @psalm-suppress TypeDoesNotContainNull
             */
            if ($bufferLength === false || $buffer === null) {
                $message = socket_strerror(socket_last_error($this->socket));
                throw new HeaderException(sprintf('Unable to read payload from socket: %s', $message));
            }

            $payload .= $buffer;
            $length -= $bufferLength;
        }

        return Frame::initFrame($parts, $payload);
    }

    public function isConnected(): bool
    {
        return $this->socket != null;
    }

    /**
     * Close connection.
     *
     * @throws RelayException
     */
    public function close()
    {
        if (! $this->isConnected()) {
            throw new RelayException("unable to close socket '{$this}', socket already closed");
        }

        $this->socket->close();
        $this->socket = null;
    }

    public function hasFrame(): bool
    {
        if (! $this->isConnected()) {
            return false;
        }

        $read = [$this->socket];
        $write = [];
        $except = [];

        $is = swoole_client_select($read, $write, $except, 0);

        return $is > 0;
    }
}
