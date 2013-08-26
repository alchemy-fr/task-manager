<?php

/*
 * This file is part of Alchemy Task Manager
 *
 * (c) 2013 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\TaskManager;

use Alchemy\TaskManager\Exception\RuntimeException;

class ZMQSocket
{
    private $socket;
    private $protocol;
    private $host;
    private $port;
    private $bound = false;

    public function __construct(\ZMQSocket $socket, $protocol, $host, $port)
    {
        $this->socket = $socket;
        $this->protocol = $protocol;
        $this->host = $host;
        $this->port = $port;
    }

    public function bind()
    {
        try {
            $this->socket->bind("$this->protocol://$this->host:$this->port");
        } catch (\ZMQSocketException $e) {
            throw new RuntimeException("Unable to bind socket to $this->protocol://$this->host:$this->port. Is another one already bound ?", $e->getCode(), $e);
        }
        $this->bound = true;
    }

    public function unbind()
    {
        try {
            $this->socket->unbind("$this->protocol://$this->host:$this->port");
        } catch (\ZMQSocketException $e) {
            throw new RuntimeException("Unable to unbind socket on $this->protocol://$this->host:$this->port.", $e->getCode(), $e);
        }
        $this->bound = false;
    }

    public function isBound()
    {
        return $this->bound;
    }

    public function __call($name, $arguments)
    {
        if (method_exists($this->socket, $name)) {
            return call_user_func_array(array($this->socket, $name), $arguments);
        }

        throw new \BadMethodCallException(sprintf('Method %s is undefined', $name));
    }

    public static function create(\ZMQContext $context, $type, $protocol, $host, $port)
    {
        return new static($context->getSocket($type), $protocol, $host, $port);
    }
}
