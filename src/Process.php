<?php

namespace Drewlabs\Async;

interface Process
{
    /**
     * Returns the process id
     * 
     * @return string|int 
     */
    public function id();

    /**
     * Send a value to the process execution context
     * @param mixed $value 
     * @return void 
     */
    public function send($value): void;

    /**
     * Run / Start process
     * @return mixed 
     */
    public function run();

    /**
     * Throw from the process context
     * 
     * @return mixed
     */
    public function throw(\Throwable $e);


    /**
     * Checks if the process is completed
     * 
     * @return bool 
     */
    public function completed(): bool;

    /**
     * Returns the value returned by the coroutine
     * 
     * @return mixed 
     */
    public function getReturn();

    /**
     * Get the coroutine used for creating clones of 
     * the current process
     * 
     * @return static 
     */
    public function getCoroutine();
}