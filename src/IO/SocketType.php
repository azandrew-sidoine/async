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

class SocketType
{
    /**
     * Specify a read socket type.
     */
    public const READ = 4;

    /**
     * Specify a write socket type.
     */
    public const WRITE = 2;

    /**
     * List of possible socket type values.
     */
    public const VALUES = [self::READ, self::WRITE];

    /**
     * Check if the provided socket type is a valid socket type.
     *
     * @param string|int $type
     *
     * @return bool
     */
    public static function valid($type)
    {
        return \in_array((int) $type, static::VALUES, true);
    }
}
