<?php

namespace Drewlabs\Async\IO;

class SocketType
{

    /**
     * Specify a read socket type
     */
    const READ = 4;

    /**
     * Specify a write socket type
     */
    const WRITE = 2;

    /**
     * List of possible socket type values
     */
    const VALUES = [self::READ, self::WRITE];

    /**
     * Check if the provided socket type is a valid socket type
     * 
     * @param string|int $type
     *  
     * @return bool 
     */
    public static function valid($type)
    {
        return in_array((int)$type, static::VALUES, true);
    }
}
