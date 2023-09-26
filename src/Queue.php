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

/**
 * @template T
 *
 * @psalm-template T
 */
interface Queue
{
    /**
     * Enqueue a process on the scheduler stack.
     *
     * @param mixed $value
     *
     * @return void
     */
    public function enqueue($value);

    /**
     * Remove a task from the top of the stack and return the removed item.
     *
     * @return mixed
     */
    public function dequeue();

    /**
     * Checks if the queue is empty.
     *
     * @return bool
     */
    public function isEmpty();

    /**
     * Find index of an element in the queue using a predicate function.
     *
     * @return int|string
     */
    public function findIndex(callable $predicate);

    /**
     * Find an element in the queue using a predicate function.
     *
     * @return T
     */
    public function find(callable $predicate);

    /**
     * Returns the list of values on the queue.
     *
     * @return T[]
     */
    public function values();

    /**
     * Clear the queue instance.
     *
     * @return void
     */
    public function clear();

    /**
     * Remove an item from the queue.
     *
     * @param int|string $index
     *
     * @return void
     */
    public function remove($index);
}
