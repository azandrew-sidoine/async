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

interface Process
{
    /**
     * Returns the process id.
     *
     * @return string|int
     */
    public function id();

    /**
     * Send a value to the process execution context.
     *
     * @param mixed $value
     */
    public function send($value): void;

    /**
     * Run / Start process.
     *
     * @return mixed
     */
    public function run();

    /**
     * Throw from the process context.
     *
     * @return mixed
     */
    public function throw(\Throwable $e);

    /**
     * Checks if the process is completed.
     */
    public function completed(): bool;

    /**
     * Returns the value returned by the coroutine.
     *
     * @return mixed
     */
    public function getReturn();

    /**
     * Get the coroutine used for creating clones of
     * the current process.
     *
     * @return static
     */
    public function getCoroutine();
}
