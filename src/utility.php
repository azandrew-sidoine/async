<?php

namespace Drewlabs\Async\Utility;

use Drewlabs\Async\IO\IoPoll;
use Drewlabs\Async\IO\SocketType;
use Drewlabs\Async\Process;
use Drewlabs\Async\ProcessLoop;
use Drewlabs\Async\Queue;
use Drewlabs\Async\ReturnValue;
use Drewlabs\Async\SysCall;
use Generator;
use InvalidArgumentException;

/**
 * Async return value factory function
 * 
 * @param mixed $value 
 * @return ReturnValue 
 */
function returnValue($value)
{
    return new class($value) implements ReturnValue
    {
        /**
         * @var T
         */
        private $v;

        public function __construct($v)
        {
            $this->v = $v;
        }

        public function value()
        {
            return $this->v;
        }
    };
}


/**
 * Create a coroutine instance from a callable instance
 * or from a PHP Generator.
 * 
 * @param callable|\Generator $generator 
 * @return Generator<mixed, mixed, mixed, mixed> 
 * @throws Throwable 
 */
function createCoroutine($v)
{
    /**
     * @var Generator[]
     */
    $stack = [];
    /**
     * @var \Throwable
     */
    $exception = null;

    try {
        $v = is_callable($v) ? call_user_func_array($v, []) : $v;
    } catch (\Throwable $e) {
        $exception = $e;
    }

    $generator = $v instanceof Generator ? $v : (function () use ($v) {
        yield;
        return $v;
    })();

    for (;;) {
        try {
            if (null !== $exception) {
                $generator->throw($exception);
                $exception = null;
                continue;
            }
            $value = $generator->current();
            if ($value instanceof \Throwable) {
                // Case the stack is empty and and exception is yielded,
                // we return the exception as value from the stack
                // for async handlers to throw them as exception
                if (empty($stack)) {
                    return $value;
                }
            }


            if ($value instanceof Generator) {
                array_push($stack, $generator);
                $generator = $value;
                continue;
            }

            $isCoReturn = $value instanceof ReturnValue;
            if (!$generator->valid() || $isCoReturn) {
                if (empty($stack)) {
                    // Case the generator returns, we do not break the
                    // it return value, therefore we return the value returned by the
                    // generator when the stack becomes empty
                    $generator->next();
                    return $generator->getReturn();
                }
                $generator = array_pop($stack);
                $generator->send($isCoReturn ? $value->value() : null);
                continue;
            }

            try {
                $sendValue = (yield $generator->key() => $value);
                $generator->send($sendValue);
            } catch (\Throwable $e) {
                $generator->throw($e);
                continue;
            }
        } catch (\Throwable $e) {
            if (empty($stack)) {
                throw $e;
            }
            $generator = array_pop($stack);
            $exception = $e;
        }
    }
}


/**
 * Creates an object that is treated by the scheduler as
 * system call on a process object. They are used for kill, spawn, etc...
 * 
 * @param callable $closure 
 * @return SysCall 
 */
function createSysCall(callable $closure)
{
    return new class($closure) implements SysCall
    {
        /**
         * @var callable
         */
        private $call;

        /**
         * Creates a sys call object instance
         * 
         * @param callable $call 
         */
        public function __construct(callable $call)
        {
            $this->call = $call;
        }

        public function __invoke(...$args)
        {
            return call_user_func_array($this->call, $args);
        }
    };
}


/**
 * `Queue` interface factory function
 * 
 * @return Queue 
 */
function createQueue()
{
    return new class implements Queue
    {

        private $queue = [];

        public function __construct()
        {
        }

        /**
         * Enqueue a process on the scheduler stack
         * 
         * @param mixed $value 
         * @param mixed $taskQueue 
         * @return void 
         */
        public function enqueue($value)
        {
            $this->queue[] = $value;
        }

        /**
         * Remove a task from the top of the stack and return the removed item
         * @param mixed $taskQueue 
         * @return mixed 
         */
        public function dequeue()
        {
            $process = array_shift($this->queue);
            return $process;
        }

        public function isEmpty()
        {
            return empty($this->queue);
        }

        public function findIndex(callable $predicate)
        {
            $index = -1;
            foreach ($this->queue as $key => $value) {
                if (call_user_func($predicate, $value)) {
                    $index = $key;
                    break;
                }
            }
            return $index;
        }

        public function find(callable $predicate)
        {
            if (-1 === ($index = $this->findIndex($predicate))) {
                return null;
            }
            return $this->queue[$index];
        }

        public function values()
        {
            return array_values($this->queue);
        }

        public function clear()
        {
            $this->queue = [];
        }

        public function remove($index)
        {
            unset($this->queue[$index]);
        }
    };
}


/**
 * Sys call that wait read action on a PHP socket resource. It pauses the couroutine
 * until the socket is available for read operation an io poll.
 * 
 * @param mixed $socket 
 * @return SysCall 
 */
function waitForRead($socket)
{
    return createSysCall(function (Process $process, ProcessLoop $poll, IoPoll $ioPoll) use ($socket) {
        $ioPoll->addSocket($socket, $process, SocketType::READ);
    });
}

/**
 * Sys call that wait write action on a PHP socket resource. It pauses the couroutine
 * until the socket is available for write operation in an io poll.
 * 
 * @param mixed $socket 
 * @return SysCall 
 */
function waitForWrite($socket)
{
    return createSysCall(function (Process $process, ProcessLoop $poll, IoPoll $ioPoll) use ($socket) {
        $ioPoll->addSocket($socket, $process, SocketType::WRITE);
    });
}

/**
 * Syscall that resolve process id for a running task
 * 
 * @return SysCall 
 */
function processId()
{
    return createSysCall(function (Process $process, ProcessLoop $poll) {
        $process->send($process->id());
        $poll->schedule($process);
    });
}


/**
 * Syscall that spwan a child process (subroutine)
 * @param callable|\Generator $subroutine 
 * @return SysCall 
 */
function spawn($subroutine)
{
    return createSysCall(function (Process $process, ProcessLoop $poll) use ($subroutine) {
        $process->send($poll->add($subroutine, $process->id()));
        $poll->schedule($process);
    });
}

/**
 * Create a fork of a given process id.
 * 
 * **Note** Only callable|Closure based process can be forked.
 * Trying to fork a process generated from a directed `Generator`
 * will fail as Generator are not clonable.
 * 
 * @param mixed $tId 
 * @return SysCall 
 */
function fork($tId)
{
    return createSysCall(function (Process $process, ProcessLoop $poll) use ($tId) {
        // Fork the `tId` process
        if (false !== ($forkId = $poll->fork($tId, $process->id()))) {
            $process->send($forkId);
            $poll->schedule($process);
            return;
        }
        throw new InvalidArgumentException(sprintf("Unable to fork process id: %s", $tId));
    });
}

/**
 * Syscall instance that kills a process (subroutine)
 * 
 * @param mixed $tid 
 * @return SysCall 
 */
function kill($tid)
{
    return createSysCall(function (Process $process, ProcessLoop $poll) use ($tid) {
        if ($poll->kill($tid)) {
            $poll->schedule($process);
            return;
        }
        throw new InvalidArgumentException(sprintf('Invalid task Id : %s', $tid));
    });
}



// #region TODO : Review Suspend, resume and stop sys call
function suspend()
{
    return createSysCall(function (Process $process, ProcessLoop $poll) {
        // Pauses scheduler
        $poll->pause();
        $poll->schedule($process);
    });
}

function resume()
{
    return createSysCall(function (Process $process, ProcessLoop $poll) {
        // Resume a paused scheduler
        $poll->resume();
        $poll->schedule($process);
    });
}

function close()
{
    return createSysCall(function ($_, ProcessLoop $poll) {
        // Stop the scheduler
        $poll->stop();
    });
}
// #region TODO : Review Suspend, resume and stop sys call