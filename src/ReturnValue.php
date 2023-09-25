<?php

namespace Drewlabs\Async;

/**
 * @template T
 * @psalm-template T
 */
interface ReturnValue
{
    /**
     * Return the corouting return value
     * 
     * @return T
     * @psalm-return T 
     */
    public function value();
}