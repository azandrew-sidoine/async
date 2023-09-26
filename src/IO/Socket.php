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

interface Socket
{
    /**
     * Read bytes from the socket. It returns the number of
     * bytes read.
     *
     * @return CoReturnValue<int|false>
     */
    public function read(int $length);

    /**
     * Write bytes to the socket. It returns the number of
     * bytes read.
     *
     * @return CoReturnValue<int|false>
     */
    public function write(string $data);

    /**
     * Close the socket resource.
     *
     * @return void
     */
    public function close();

    /**
     * Check if eof file is reached.
     *
     * @return bool
     */
    public function eof();
}
