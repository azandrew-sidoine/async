<?php

namespace Drewlabs\Async;

interface Awaitable
{
    /**
     * Wait for a routine to complete before executing next call in the stack
     * 
     * @return void 
     */
    public function wait(): void;
}