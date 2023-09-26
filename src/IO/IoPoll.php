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

interface IoPoll
{
    /**
     * Add a read/write socket to the socket poll with the process
     * that interacts with the socket.
     *
     * @param mixed $socket
     * @param mixed $process
     * @param mixed $type    Socket type argument defining which operation the socket support
     *                       (Ex: SocketType::READ, SocketType::WRITE)
     *
     * @throws InvalidArgumentException
     *
     * @return void
     */
    public function addSocket($socket, $process, int $type);

    /**
     * Runs PHP `stream_select` on the list of read an write socket streams
     * to insure the socket are ready for any operation.
     *
     * It will schedule the process bind to the socket case the socket is ready.
     *
     * @return void
     */
    public function select(ProcessLoop $processPoll, int $timeout = null);

    /**
     * Starts the io poll tasks.
     *
     * @return Generator<int, null, mixed, void>
     */
    public function start(ProcessLoop $processPoll);

    /**
     * Stop the io poll task.
     *
     * @return void
     */
    public function stop();
}
