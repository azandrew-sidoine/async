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

namespace Drewlabs\Async\Future;

use Drewlabs\Async\Awaitable;
use Drewlabs\Async\PromiseInterface;

use function Drewlabs\Async\Scheduler\createProcessLoop;
use function Drewlabs\Async\Utility\spawn;

/**
 * A promise factory function that create promise A+ specification
 * instance.
 *
 * It takes a waitable function with a reference to the `revolve`
 * function as first param and a reference to the `reject` function
 * of the promise instance as second parameter.
 *
 * ```php
 *
 * $promise = promise(function($resolve, $reject) {
 *      // Do something with resolve
 *      usleep(1000*1000); // Block PHP execution
 *      resolve("I have been waited for 1 second.");
 * });
 *
 * // Wait for the promise
 * $promise->wait();
 * ```
 *
 * **Note** In the above example, without calling `wait()`, the promise
 * coroutine to execute, does not run.
 *
 * To create a promise that automatically run on PHP process shutdown, the
 * factory function takes a second boolean flag as parameter.
 *
 * ```php
 * // Here the script create a promise instance that executes when
 * // PHP process shutdown with success
 * $promise = promise(function($resolve, $reject) {
 *      // Do something with resolve
 *      usleep(1000*1000); // Block PHP execution
 *      resolve("I have been waited for 1 second.");
 * }, true);
 * ```
 *
 * @return PromiseInterface<T>&Awaitable
 */
function promise(callable $waitFn = null, bool $shutdown = false)
{

    return new class($waitFn, $shutdown) implements PromiseInterface, Awaitable {
        /**
         * @var bool
         */
        private $resolved = false;
        /**
         * @var bool
         */
        private $rejected = false;
        /**
         * @var array
         */
        private $handlersQueue = [];
        /**
         * @var T
         */
        private $value;
        /**
         * @var \Throwable
         */
        private $error;

        /**
         * Set a callable instance that resolves the promise.
         *
         * @var callable
         */
        private $waitFn;

        /**
         * Create new promise instance.
         *
         * @param bool $shutdown Configure the promise to automatically run when PHP
         *                       shutdown function is called
         *
         * @return void
         */
        public function __construct(callable $waitFn = null, $shutdown = false)
        {
            $this->waitFn = $waitFn;
            // Case shutdown flag is passed to the promise factory
            // constructor, we register a function that runs on shudown
            // the coroutine if an E_ERROR didn't occur.
            if ($shutdown) {
                register_shutdown_function(function () {
                    $this->wait();
                });
            }
        }

        public function then(callable $done, callable $error = null)
        {
            $completed = $this->isComplete();
            if ($completed) {
                // Case the promise is completed, there is no need to pass the wait fn to the
                // returned promise. We instead pass a fulfilled/rejected promise instance
                $promise = $this->isResolved() ? fulfilled($this->value) : rejected($this->error);
            } else {
                $promise = new static($this->waitFn);
            }
            $handler = [$done, $error ?: $this->useDefaultError(), $promise];
            if ($completed) {
                // Process the last handler if the promise completeds
                $this->processHandler(...$handler);
            } else {
                $this->handlersQueue[] = $handler;
            }

            return $promise;
        }

        public function catch(callable $error)
        {
            return $this->then(static function () {
            }, $error);
        }

        public function resolve($value = null): void
        {
            $this->resolved = true;
            $this->value = $value;
            $this->processQueue();
        }

        public function reject($error = null): void
        {
            $this->rejected = true;
            $this->error = $error;
            if (\count($this->handlersQueue)) {
                $this->processQueue();
            } else {
                ($this->useDefaultError())($error);
            }
        }

        public function wait(): void
        {
            // Calling wait checks if promise has been completed
            // before. Case the promise has been completed once, wait does
            // not call the co-routine anymore. Instead it return
            if (!$this->isComplete() && $this->waitFn) {
                $arguments = [\Closure::fromCallable([$this, 'resolve']), \Closure::fromCallable([$this, 'reject'])];
                \call_user_func_array($this->waitFn, $arguments);
            }
        }

        private function isResolved(): bool
        {
            return $this->resolved;
        }

        private function isRejected(): bool
        {
            return $this->rejected;
        }

        private function isComplete(): bool
        {
            return $this->isRejected() || $this->isResolved();
        }

        private function processQueue(): void
        {
            foreach ($this->handlersQueue as $handler) {
                $this->processHandler(...$handler);
            }
            $this->handlersQueue = [];
        }

        private function useDefaultError(): callable
        {
            return static function (\Exception|\Error $e) {
                throw $e;
            };
        }

        private function processHandler(callable $done, callable $error, self $promise): void
        {
            $callback = $this->isResolved() ? $done : $error;
            $argument = $this->isResolved() ? $this->value : $this->error;
            try {
                $result = $callback($argument);
                if ($result instanceof PromiseInterface) {
                    $result
                        ->then(static function ($v) use ($promise) {
                            $promise->resolve($v);
                        })
                        ->catch(static function ($e) use ($promise) {
                            $promise->resolve($e);
                        });
                } else {
                    $promise->resolve($result);
                }
            } catch (\Exception|\Error $e) {
                $promise->reject($e);
            }
        }
    };
}

/**
 * @template T
 *
 * Creates a promise that resolve the value passed in as parameter
 *
 * @param T $value
 *
 * @return PromiseInterface<T>&Awaitable
 */
function fulfilled($value)
{
    $promise = promise(static function ($resolve) use ($value) {
        $resolve($value);
    });

    // Wait the promise for wait function to get executed
    $promise->wait();

    // Return the promise instance
    return $promise;
}

/**
 * @template T
 *
 * Creates a promise that reject the error passed in as parameter
 *
 * @return PromiseInterface<T>&Awaitable
 */
function rejected($error)
{
    $promise = promise(static function ($_, $reject) use ($error) {
        $reject($error);
    });

    // Wait the promise for wait function to get executed
    $promise->wait();

    // Return the promise instance
    return $promise;
}

/**
 * Provides an asynchronous function execution context for the subroutine
 * passed as argument.
 * It returns a `Awaitable` instance which start
 * the subroutine when `wait()` is invoked on it. The `wait()` statement
 * as it will pause the script execution until the subroutine complete.
 *
 * The returned instance is a promise instance, therefore developpers can
 * `then` on the returned value to get the value produced by the subroutine.
 *
 * ```php
 * $promise = async(function () {
 *  printf("Calling coroutine...\n");
 *  usleep(1000 * 2000);
 *  return 'awaited';
 * });
 *
 * $promise->then(function($value) {
 *      printf("%s...", $value); // awaited...
 * });
 *
 * // ...
 *
 * // Start the async routine
 * $promise->wait();
 * ```
 *
 * @template T
 *
 * @param callable|\Generator $coroutine
 *
 * @return PromiseInterface&Awaitable
 */
function async($coroutine)
{
    return promise(static function ($resolve, $reject) use ($coroutine) {
        $poll = createProcessLoop(true);
        // Schedule the current job as task
        try {
            $job = $poll->add($coroutine);
            // Starts the scheduler
            $poll->start(static function ($id, $result) use ($job, $poll, $resolve, $reject) {
                if ($job !== $id) {
                    return;
                }
                if ($result instanceof \Throwable) {
                    $reject($result);
                } else {
                    $resolve($result);
                }
                $poll->stop();
            });
        } catch (\Throwable $e) {
            $reject($e);
        }
    });
}

/**
 * Create a promise instance that executes resolve and reject callbacks
 * when on PHP process shutdown. It's the simply version of `promise($waitFn, true)`.
 *
 * ```php
 * // Here the script create a promise instance that executes when
 * // PHP process shutdown with success
 * $promise = defer(function($resolve, $reject) {
 *      // Do something with resolve
 *      usleep(1000*1000); // Block PHP execution
 *      resolve("I have been waited for 1 second.");
 * }, true);
 * ```
 *
 * @param callable|\Closure(callable $resolve, callable $reject = null) $waitFn
 *
 * @return PromiseInterface<Drewlabs\Async\Future\T>&Awaitable
 */
function defer($waitFn)
{
    return promise($waitFn, true);
}

/**
 * `join`, is same as `async` interface, except the fact that is takes
 * a list of subroutines wait on the result of those subroutines and return
 * the a list of the awaited result in the order they were inserted.
 *
 * ```php
 * $promise = join(
 *      function () {
 *          printf("Calling coroutine...\n");
 *          yield usleep(1000 * 2000); // blocking
 *          return 'awaited';
 *      },
 *      function () {
 *          printf("Calling second coroutine...\n");
 *          yield usleep(1000 * 2000); // blocking
 *          return 'awaited 2';
 *      },
 * );
 *
 * $promise->then(function($value) {
 *      print_r($value); // ['awaited', 'awaited 2']
 * });
 *
 * // ...
 *
 * // Start the async routine
 * $promise->wait();
 * ```
 *
 * @template T
 *
 * @return PromiseInterface<T[]>&Awaitable
 */
function join(...$coroutines)
{
    return promise(static function ($resolve, $reject) use ($coroutines) {
        $poll = createProcessLoop(true);
        $outputs = [];
        $total = \count($coroutines);
        // Schedule the current job as task
        $parentTask = static function () use ($coroutines, &$outputs) {
            foreach ($coroutines as $coroutine) {
                $job = (yield spawn($coroutine));
                $outputs[$job] = null;
                yield;
            }
        };
        $pProcessId = $poll->add($parentTask());
        try {
            // Starts the scheduler
            $poll->start(static function ($id, $result) use ($pProcessId, $total, $poll, &$outputs, $reject) {
                // We only care about current process child processes
                if (!str_starts_with($id, sprintf('%s_', $pProcessId))) {
                    return;
                }
                if ($result instanceof \Exception) {
                    $reject($result);
                }
                $outputs[$id] = $result;
                // We stop the scheduler whenever all jobs completes
                $hasPendingJobs = \count($outputs) !== $total || (false !== array_search(null, $outputs, true));
                if (false === $hasPendingJobs) {
                    $poll->stop();
                }
            });
            $resolve(array_values($outputs));
        } catch (\Throwable $e) {
            $reject($e);
        }
    });
}

/**
 * Await a subroutine and return the awaited result.
 *
 * @param callable|\Generator<T>|PromiseInterface<T>|Awaitable $coroutine
 *
 * @return T
 */
function await($coroutine)
{
    /**
     * @var T
     */
    $value = null;
    $promise = $coroutine instanceof PromiseInterface ? $coroutine : async($coroutine);

    $promise->then(static function ($resolve) use (&$value) {
        $value = $resolve;
    }, static function ($e) {
        throw $e instanceof \Throwable ? $e : new \Exception($e);
    });

    // Wait on the subroutine to complete executing
    $promise->wait();

    // Return then result to caller
    return $value;
}

/**
 * Wait on all subroutines to complete and returns the returned values of the subroutines.
 *
 * @param array<callable|\Generator<T>>|callable|\Generator<T>|PromiseInterface<T>|Awaitable $coroutines
 *
 * @return T[]
 */
function all($coroutines)
{
    /**
     * @var T[]
     */
    $value = null;
    $promise = $coroutines instanceof PromiseInterface ? $coroutines : join(...(\is_array($coroutines) ? $coroutines : [$coroutines]));

    $promise->then(static function ($resolve) use (&$value) {
        $value = $resolve;
    }, static function ($e) {
        throw $e instanceof \Throwable ? $e : new \Exception($e);
    });

    // Wait on the subroutine to complete executing
    $promise->wait();

    // Return then result to caller
    return $value;
}
