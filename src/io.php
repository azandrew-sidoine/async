<?php

declare(strict_types=1);

/*
 * This file is part of the drewlabs namespace.
 *
 * (c) Sidoine Azandrew <azandrewdevelopper@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Drewlabs\Async\IO;

use Drewlabs\Async\ProcessLoop;

use function Drewlabs\Async\Utility\returnValue;
use function Drewlabs\Async\Utility\waitForRead;
use function Drewlabs\Async\Utility\waitForWrite;

/**
 * @param resource $socket
 *
 * @return \Generator<int, mixed, mixed, void>
 */
function socketAccept($socket)
{
    yield waitForRead($socket);
    yield returnValue(createSocket(stream_socket_accept($socket, 0)));
}

/**
 * Creates a that wait on read and write operation.
 *
 * @param mixed $socket
 *
 * @return Socket
 */
function createSocket($socket)
{
    return new class($socket) implements Socket {
        /**
         * @var int|resource
         */
        private $socket;

        public function __construct($socket)
        {
            $this->socket = $socket;
        }

        public function __toString()
        {
            if (!\is_resource($this->socket)) {
                return '';
            }

            return (string) $this->socket;
        }

        public function read(int $length)
        {
            yield waitForRead($this->socket);
            yield returnValue(fread($this->socket, $length));
        }

        public function write(string $data)
        {
            yield waitForWrite($this->socket);
            yield returnValue(fwrite($this->socket, $data));
        }

        public function eof()
        {
            return @feof($this->socket);
        }

        public function close()
        {
            yield returnValue(@fclose($this->socket));
        }
    };
}

/**
 * Creates an IO poll instance.
 *
 * @return IoPoll
 */
function createIoPoll()
{
    return new class() implements IoPoll {
        /**
         * @var bool
         */
        private $stopped = true;

        /**
         * @var array<int,array<resource,mixed>>
         */
        private $readSockets = [];

        /**
         * @var array<int,array<resource,mixed>>
         */
        private $writeSockets = [];

        public function addSocket($socket, $process, int $type)
        {
            if (!SocketType::valid($type)) {
                throw new \InvalidArgumentException(sprintf('Unsupported socket type, supported socket types are %s', implode('', SocketType::VALUES)));
            }
            $addSocketClosures = [
                SocketType::READ => function ($sock, $proc) {
                    if (isset($this->readSockets[(int) $sock])) {
                        $this->readSockets[(int) $sock][1][] = $proc;
                    } else {
                        $this->readSockets[(int) $sock] = [$sock, [$proc]];
                    }
                },
                SocketType::WRITE => function ($sock, $proc) {
                    if (isset($this->writeSockets[(int) $sock])) {
                        $this->writeSockets[(int) $sock][1][] = $proc;
                    } else {
                        $this->writeSockets[(int) $sock] = [$sock, [$proc]];
                    }
                },
            ];
            \call_user_func_array($addSocketClosures[(int) $type], [$socket, $process]);
        }

        public function select(ProcessLoop $processPoll, int $timeout = null)
        {
            if (empty($this->readSockets) && empty($this->writeSockets)) {
                return;
            }

            $rSocks = $this->getSockets($this->readSockets);
            $wSocks = $this->getSockets($this->writeSockets);
            $eSocks = []; // dummy

            if (!stream_select($rSocks, $wSocks, $eSocks, $timeout)) {
                return;
            }

            foreach ($rSocks as $socket) {
                [, $tasks] = $this->readSockets[(int) $socket];
                unset($this->readSockets[(int) $socket]);
                foreach ($tasks as $task) {
                    $processPoll->schedule($task);
                }
            }

            foreach ($wSocks as $socket) {
                [, $tasks] = $this->writeSockets[(int) $socket];
                unset($this->writeSockets[(int) $socket]);
                foreach ($tasks as $task) {
                    $processPoll->schedule($task);
                }
            }
        }

        public function start(ProcessLoop $processPoll)
        {
            $this->stopped = false;
            while (true) {
                if ($this->stopped) {
                    break;
                }
                if ($processPoll->hasProcesses()) {
                    $this->select($processPoll, null);
                } else {
                    $this->select($processPoll, 0);
                }
                yield;
            }
        }

        /**
         * Stop the io poll task.
         *
         * @return void
         */
        public function stop()
        {
            $this->stopped = true;
        }

        /**
         * Get list of sockets (resources) in the io scheduler instance.
         *
         * @param mixed $values
         *
         * @return array
         */
        private function getSockets($values)
        {
            $sockets = [];
            if (!empty($values)) {
                foreach ($values as [$socket]) {
                    $sockets[] = $socket;
                }
            }

            return $sockets;
        }
    };
}
