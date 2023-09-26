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

namespace Drewlabs\Async;

interface ProcessLoop
{
    /**
     * Schedule a new process to be executed on the next frame of the process loop.
     *
     * @return void
     */
    public function schedule(Process $process);

    /**
     * Add a new process to the process poll. Process are lightweight PHP subroutine
     * created from a Generator function or a callable (preffered).
     *
     * It takes a second argument which is the process parent id. Implementation should
     * make it possible to identify process `spawn` or `forked` from a parent process.
     *
     * It returns the added process id.
     *
     * @param callable|Generator $subroutine
     * @param string|int|null    $parent
     *
     * @return string|int
     */
    public function add($subroutine, $parent = null);

    /**
     * Create a fork or a clone of a given process.
     * It returns the forked process id.
     *
     * @param string|int $process
     *
     * @return string|int|false
     */
    public function fork($process);

    /**
     * Kill the process matching the provided process id.
     *
     * @param int|string $pid
     */
    public function kill($pid): bool;

    /**
     * Checks if process poll is empty.
     *
     * @return bool
     */
    public function hasProcesses();

    /**
     * Starts the io poll tasks.
     *
     * @return void
     */
    public function start(callable $processResult = null);

    /**
     * Stop the io poll task.
     *
     * @return void
     */
    public function stop();

    /**
     * Pause the process poll loop.
     *
     * @return bool
     */
    public function pause();

    /**
     * Resume the process poll loop.
     *
     * @return bool
     */
    public function resume();
}
