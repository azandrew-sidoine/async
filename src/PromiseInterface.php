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
 * @psalm-template T
 *
 * @template T
 */
interface PromiseInterface
{
    /**
     * Block the.
     *
     * @return PromiseInterface
     */
    public function then(callable $done, callable $error = null);

    /**
     * Resolve new promise value.
     *
     * @param mixed $value
     */
    public function resolve($value = null): void;

    /**
     * Resolve new promise exception.
     */
    public function reject($error = null): void;

    /**
     * Catch error on the promise instance.
     *
     * @return static
     */
    public function catch(callable $error);
}
