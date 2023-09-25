<?php

namespace Drewlabs\Async;

interface SysCall
{
    /**
     * Simulates a syscall for the scheduler API
     * @param mixed $args 
     * @return mixed 
     */
    public function __invoke(...$args);
}
