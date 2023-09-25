<?php

namespace Drewlabs\Async\Scheduler;

use Closure;
use Drewlabs\Async\Process;
use Drewlabs\Async\ReturnValue;
use Drewlabs\Async\Socket;
use Drewlabs\Async\SysCall;
use Drewlabs\Async\Queue;
use Generator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

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
 * 
 * @param resource $socket 
 * @return Generator<int, mixed, mixed, void> 
 */
function socketAccept($socket)
{
    yield waitForRead($socket);
    yield returnValue(createSocket(stream_socket_accept($socket, 0)));
}

/**
 * Creates a that wait on read and write operation.
 * @param mixed $socket 
 * @return Socket 
 */
function createSocket($socket)
{
    return new class($socket) implements Socket
    {
        /**
         * 
         * @var int|resource
         */
        private $socket;

        public function __construct($socket)
        {
            $this->socket = $socket;
        }

        public function read(int $length)
        {
            yield waitForRead($this->socket);
            yield returnValue(fread($this->socket, $length));
        }

        public function write(string $data)
        {
            yield waitForWrite($this->socket);
            yield returnValue(fwrite($this->socket, $data));
        }

        public function eof()
        {
            return @feof($this->socket);
        }

        public function close()
        {
            yield returnValue(@fclose($this->socket));
        }
    };
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
 * Syscall that resolve process id for a running task
 * 
 * @return SysCall 
 */
function processId()
{
    return createSysCall(function (Process $process, array $scheduler) {
        $process->send($process->id());
        list($schedule) = $scheduler;
        $schedule($process);
    });
}

function suspend()
{
    return createSysCall(function (Process $process, array $scheduler) {
        list($schedule,,,, $pause) = $scheduler;
        // Pauses scheduler
        $pause();
        $schedule($process);
    });
}

function resume()
{
    return createSysCall(function (Process $process, array $scheduler) {
        list($schedule,,,,, $resume) = $scheduler;
        // Resume a paused scheduler
        $resume();
        $schedule($process);
    });
}

function close()
{
    return createSysCall(function ($_, array $scheduler) {
        list(,,,,,, $stop) = $scheduler;
        // Stop the scheduler
        $stop();
    });
}

/**
 * Syscall that spwan a child process (subroutine)
 * @param callable|\Generator $coroutine 
 * @return SysCall 
 */
function spawn($coroutine)
{
    return createSysCall(function (Process $process, array $scheduler) use ($coroutine) {
        list($schedule, $addTask) = $scheduler;
        $process->send($addTask($coroutine, $process->id()));
        $schedule($process);
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
    return createSysCall(function (Process $process, array $scheduler) use ($tId) {
        list($schedule,,, $fork) = $scheduler;
        // Fork the `tId` process
        if (false !== ($forkId = $fork($tId, $process->id()))) {
            $process->send($forkId);
            $schedule($process);
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
    return createSysCall(function (Process $process, array $scheduler) use ($tid) {
        list($schedule,, $killTask) = $scheduler;
        if ($killTask($tid)) {
            $schedule($process);
            return;
        }
        throw new InvalidArgumentException(sprintf('Invalid task Id : %s', $tid));
    });
}


/**
 * Create a coroutine object that communicate with the scheduler
 * and perform a given action. Action to be performed is passed
 * as PHP callable or `Generator`.
 * 
 * @param int|string $id
 * @param callable|\Generator $coroutine
 * @return Process
 * @throws Throwable 
 */
function process($id, $coroutine)
{
    return new class($id, $coroutine) implements Process
    {
        /**
         * 
         * @var callable|Generator
         */
        private $coroutine;

        /**
         * @var bool
         */
        private $yielded = false;

        /**
         * @var mixed
         */
        private $value = null;

        /**
         * @var \Throwable
         */
        private $exception = null;

        /**
         * @var callable
         */
        private $factory;

        /**
         * @var int|string
         */
        private $id;

        /**
         * Creates a new process instance
         * 
         * @param int|string $id
         * @param mixed $coroutine
         * @return void 
         * @throws Throwable 
         */
        public function __construct($id, $coroutine)
        {
            if (is_callable($coroutine)) {
                $this->factory = \Closure::fromCallable($coroutine)->bindTo(null, 'static');
            }
            $this->id = $id;
            $this->coroutine = createCoroutine($coroutine);
        }

        public function id()
        {
            return $this->id;
        }

        public function run()
        {
            if (!$this->yielded) {
                $this->yielded = true;
                return $this->coroutine->current();
            } else if (null !== $this->exception) {
                $returnVal = $this->coroutine->throw($this->exception);
                $this->exception = null;
                return $returnVal;
            } else {
                return $this->coroutine->send($this->value);
            }
        }

        public function throw(Throwable $e)
        {
            $this->exception = $e;
        }

        public function send($value): void
        {
            $this->value = $value;
        }

        public function completed(): bool
        {
            return !$this->coroutine->valid();
        }

        public function getReturn()
        {
            return $this->completed() ? $this->coroutine->getReturn() : null;
        }

        public function getCoroutine()
        {
            if (is_callable($this->factory)) {
                return \Closure::fromCallable($this->factory);
            }
            throw new RuntimeException('Only callable or closure based processes can be cloned!');
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
         * @param mixed $process 
         * @param mixed $taskQueue 
         * @return void 
         */
        public function enqueue($process)
        {
            $this->queue[] = $process;
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
 * Get list of sockets (resources) in the io scheduler instance
 * @param mixed $values 
 * @return array 
 */
function getSockets($values)
{
    $sockets = [];
    if (!empty($values)) {
        foreach ($values as list($socket)) {
            $sockets[] = $socket;
        }
    }
    return $sockets;
}

/**
 * Start an io poll that uses PHP `stream_select` wich run `select()`
 * on list of socket objects
 * 
 * @param mixed $rSockets 
 * @param mixed $wSockets 
 * @param mixed $next 
 * @param int|null $timeout 
 * @return void 
 */
function ioPoll(&$rSockets, &$wSockets, &$next, int $timeout = null)
{
    if (empty($rSockets) && empty($wSockets)) {
        return;
    }

    $rSocks = getSockets($rSockets);
    $wSocks = getSockets($wSockets);
    $eSocks = []; // dummy

    if (!stream_select($rSocks, $wSocks, $eSocks, $timeout)) {
        return;
    }

    foreach ($rSocks as $socket) {
        list(, $tasks) = $rSockets[(int) $socket];
        unset($rSockets[(int)$socket]);
        foreach ($tasks as $task) {
            $next($task);
        }
    }

    foreach ($wSocks as $socket) {
        list(, $tasks) = $wSockets[(int) $socket];
        unset($wSockets[(int)$socket]);
        foreach ($tasks as $task) {
            $next($task);
        }
    }
};

/**
 * Sys call that wait read action on a PHP socket resource. It pauses the couroutine
 * until the socket is available for read operation an io poll.
 * 
 * @param mixed $socket 
 * @return SysCall 
 */
function waitForRead($socket)
{
    return createSysCall(function (Process $process, array $_, array $scheduler) use ($socket) {
        list($waitForRead) = $scheduler;
        $waitForRead($socket, $process);
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
    return createSysCall(function (Process $process, array $_, array $scheduler) use ($socket) {
        list(, $waitForWrite) = $scheduler;
        $waitForWrite($socket, $process);
    });
}

/**
 * Base scheduler object. This scheduler is best suit for coroutines
 * that does not interacts with system I/O. It provides a taskQueue loop
 * and block until all task on the stack are executed when run is called.
 * 
 * @return (Closure(callable|null $taskResult = null, array $sysCalls = []): void|Closure(mixed $callback, mixed $parent = null): string|Closure(): bool|Closure(mixed $task): void)[] 
 */
function scheduler()
{
    /**
     * @var Queue
     */
    $taskQueue = createQueue();

    /**
     * @var int
     */
    $maxTaskId = 0;
    /**
     * @var bool
     */
    $paused = false;
    /**
     * @var bool
     */
    $stoped = false;

    $schedule = function ($task) use ($taskQueue) {
        $taskQueue->enqueue($task, $taskQueue);
    };

    $addProcess = function ($callback, $parent = null) use (&$schedule, &$maxTaskId) {
        $id = ++$maxTaskId;
        $id = null === $parent ? "$id" : sprintf("%s_%s", $parent, $id);
        $schedule(process($id, $callback));
        return $id;
    };

    $killProcess = function ($process) use ($taskQueue) {
        // Unset the task to be killed
        $index = $taskQueue->findIndex(function ($proc) use ($process) {
            return $proc->id() === $process;
        });
        if ($index === -1) {
            return false;
        }
        $taskQueue->remove($index);
        return true;
    };

    $forkProcess = function ($process, $parent = null) use (&$addProcess, $taskQueue) {
        $queuedProcess = $taskQueue->find(function ($proc) use ($process) {
            return $proc->id() === $process;
        });
        if (null === $queuedProcess) {
            return false;
        }

        // Add a clone of the process
        $forkedProcess = $addProcess($queuedProcess->getCoroutine(), $parent);

        return $forkedProcess;
    };

    $isEmpty = function () use ($taskQueue) {
        return $taskQueue->isEmpty();
    };

    $pauseScheduler = function () use (&$paused) {
        $paused = true;
    };

    $stopScheduler = function () use (&$stoped) {
        $stoped = true;
    };

    $resumeScheduler = function () use (&$paused, &$stoped, &$startScheduler) {
        if (!$stoped) {
            $paused = false;
            $startScheduler();
        }
    };

    $startScheduler = function (callable $taskResult = null, $sysCalls = []) use (&$isEmpty, $taskQueue, &$paused, &$stoped, &$schedule, &$addProcess, &$killProcess, &$forkProcess, $pauseScheduler, $resumeScheduler, $stopScheduler) {
        while (!$isEmpty()) {
            if ($paused) {
                // We break from the scheduler without and keep the current state
                // of the taskQueue so that restating the queue 
                break;
            }
            if ($stoped) {
                // We break from the loop and reset the taskQueue so that any subsequent
                // call to start the scheduler does not block on all task queue
                break;
                $taskQueue->clear();
            }

            /**
             * @var Process $process
             */
            $process = $taskQueue->dequeue();
            $channel = $process->run();
            if ($channel instanceof SysCall) {
                // Handle exception during system calls
                try {
                    $channel($process, [$schedule, $addProcess, $killProcess, $forkProcess, $pauseScheduler, $resumeScheduler, $stopScheduler], $sysCalls ?? []);
                } catch (\Throwable $e) {
                    $process->throw($e);
                    $schedule($process);
                }
                continue;
            }
            if (!$process->completed()) {
                $schedule($process);
                continue;
            }
            if (null !== $taskResult) {
                $taskResult($process->id(), $process->getReturn());
            }
            // Removing task
            $index = $taskQueue->findIndex(function ($proc) use ($process) {
                return $proc->id() === $process->id();
            });
            if ($index) {
                $taskQueue->remove($index);
            }
        }
    };

    return [$startScheduler, $addProcess, $isEmpty, $schedule, $resumeScheduler];
};


/**
 * IO Scheduler provides a platform for I/O based coroutine. It uses system `select()`
 * and wait on read and write operations.
 * 
 * Thanks to PHP having a unify interface for handling I/O operations, the scheduler provides
 * syscall for waiting on read `waitForRead` and write `waitForWrite` operation.
 * 
 * `createSocket($socketId)` function uses these call to turn io `fwrite` and `fread` operation to subroutine
 * therefore when performing io operation that might run in the `ioScheduler`, wrap your
 * socket resources using `createSocket` for benefic performance improvement.
 * 
 * **Note** The scheduler loop is infinite, therefore, the scheduler provides developpers
 * with a stop handler that might be called manually to break the loop.
 * 
 * ```php
 * list($runScheduler, $addProcess, $stopScheduler) = ioScheduler();
 * 
 * // Add a new subroutine to the scheduler
 * $addProcess(function() {
 *      // Perform action
 * });
 * 
 * $runScheduler(function() use ($stoScheduler) {
 * 
 *      // Stop the running scheduler
 * });
 * ```
 * 
 * @return Closure[] 
 */
function ioScheduler()
{
    $readSockets = [];
    $writeSockets = [];
    $ioTask = false;
    list($startScheduler, $addProcess, $isEmpty, $schedule) = scheduler();
    $waitForRead = function ($socket, $queuedTask) use (&$readSockets) {
        if (isset($readSockets[(int)$socket])) {
            $readSockets[(int)$socket][1][] = $queuedTask;
        } else {
            $readSockets[(int)$socket] = [$socket, [$queuedTask]];
        }
    };
    $waitForWrite = function ($socket, $queuedTask) use (&$writeSockets) {
        if (isset($writeSockets[(int)$socket])) {
            $writeSockets[(int)$socket][1][] = $queuedTask;
        } else {
            $writeSockets[(int)$socket] = [$socket, [$queuedTask]];
        }
    };

    $ioPollTask = function () use (&$readSockets, &$writeSockets, &$schedule, &$isEmpty, &$ioTask) {
        while ($ioTask) {
            if ($isEmpty()) {
                ioPoll($readSockets, $writeSockets, $schedule, null);
            } else {
                ioPoll($readSockets, $writeSockets, $schedule, 0);
            }
            yield;
        }
    };

    $stopScheduler = function () use (&$readSockets, &$writeSockets, &$ioTask) {
        $ioTask = false;
        foreach (array_merge(getSockets($readSockets), getSockets($writeSockets)) as $socket) {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
    };

    $startScheduler_ = function (callable $taskResult = null) use (&$addProcess, &$ioPollTask, &$startScheduler, &$waitForRead, &$waitForWrite, &$ioTask) {
        $ioTask = true;
        $addProcess($ioPollTask());
        return $startScheduler($taskResult, [$waitForRead, $waitForWrite]);
    };

    return [$startScheduler_, $addProcess, $stopScheduler];
}
