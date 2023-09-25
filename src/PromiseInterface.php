<?php

namespace Drewlabs\Async;

/**
 * @psalm-template T
 * @template T
 */
interface PromiseInterface
{
    /**
     * Block the 
     * @param callable $done 
     * @param callable|null $error 
     * @return PromiseInterface
     */
    public function then(callable $done, callable $error = null);

    /**
     * Resolve new promise value
     * 
     * @param mixed $value 
     * @return void 
     */
    public function resolve($value = null): void;

    /**
     * Resolve new promise exception
     * 
     * @param mixed $value 
     * @return void 
     */
    public function reject($error = null): void;

    /**
     * Catch error on the promise instance
     * 
     * @param callable $error 
     * @return static
     */
    public function catch(callable $error);
}
