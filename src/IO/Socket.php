<?php

namespace Drewlabs\Async\IO;

interface Socket
{
    /**
     * Read bytes from the socket. It returns the number of
     * bytes read
     * 
     * @param int $length 
     * @return CoReturnValue<int|false> 
     */
    public function read(int $length);

    /**
     * Write bytes to the socket. It returns the number of
     * bytes read
     * 
     * @param string $data 
     * @return CoReturnValue<int|false>
     */
    public function write(string $data);

    /**
     * Close the socket resource
     * @return void 
     */
    public function close();

    /**
     * Check if eof file is reached
     * 
     * @return bool 
     */
    public function eof();
}
