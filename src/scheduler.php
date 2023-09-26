<?php

namespace Drewlabs\Async\Scheduler;

use Closure;
use Drewlabs\Async\Process;
use Drewlabs\Async\SysCall;
use Drewlabs\Async\Queue;
use Drewlabs\Async\IO\IoPoll;
use Drewlabs\Async\ProcessLoop;
use Generator;
use RuntimeException;
use Throwable;

use function Drewlabs\Async\IO\createIoPoll;
use function Drewlabs\Async\Utility\createCoroutine;
use function Drewlabs\Async\Utility\createQueue;

/**
 * Creates a process poll instance.
 * 
 * Passing true, will create a process for the io events poll, else if false, not
 * io event pool is created.
 * 
 * **Note** When the pool is created with support for IO event(ex: read,write), the poll
 * will hang event all callback tasks are completed. Therefore developpers should manually
 * stop the process poll to prevent memory leak or script to run indefinetively.
 * 
 * @param IoPoll|bool $ioPoll
 * @return ProcessLoop
 */
function createProcessLoop($ioPoll = null)
{
    return new class($ioPoll) implements ProcessLoop
    {
        /**
         * @var Queue
         */
        private $queue;

        /**
         * @var int
         */
        private $lastProcId;

        /**
         * @var bool
         */
        private $paused = false;

        /**
         * @var bool
         */
        private $stopped = false;

        /**
         * @var IoPoll
         */
        private $ioPoll;

        /**
         * @var callable
         */
        private $processResultCallback = null;

        /**
         * Create new process pool instance
         * 
         * @param IoPoll|bool|null $ioPoll 
         * @return void 
         */
        public function __construct($ioPoll = null)
        {
            $this->queue = createQueue();
            $this->ioPoll = is_bool($ioPoll) ? ($ioPoll === true ? createIoPoll() : null) : $ioPoll;
        }

        public function schedule(Process $process)
        {
            $this->queue->enqueue($process);
        }

        public function add($subroutine, $parent = null)
        {
            $id = ++$this->lastProcId;
            $id = null === $parent ? "$id" : sprintf("%s_%s", $parent, $id);

            // Schedule the process instance
            $this->schedule(process($id, $subroutine));

            // Returns the process id
            return $id;
        }

        public function fork($pid)
        {
            $processId = (string)$pid;
            // #region Get parent process id from the process id 
            $position = strpos(strrev($processId), '_');
            $parent = null;
            if (false !== $position) {
                $parent = substr($processId, 0, (strlen($processId) - $position) - 1);
            }
            // #endregion Get parent process id from the process id
            /**
             * @var Process $queuedProcess
             */
            $queuedProcess = $this->queue->find(function ($proc) use ($pid) {
                return (string)($proc->id()) === (string)$pid;
            });
            if (null === $queuedProcess) {
                return false;
            }

            // Add a clone of the process
            $forkedProcess = $this->add($queuedProcess->getCoroutine(), $parent);

            // Return the forked process id
            return $forkedProcess;
        }

        public function kill($pid): bool
        {
            // Unset the task to be killed
            $index = $this->queue->findIndex(function ($proc) use ($pid) {
                return $proc->id() === $pid;
            });
            if ($index === -1) {
                return false;
            }
            $this->queue->remove($index);

            // Return true once process is removed
            return true;
        }

        public function hasProcesses()
        {
            return $this->queue->isEmpty();
        }
    
        public function start(callable $processResult = null)
        {
            if ($this->ioPoll) {
                // Add the IO Poll task
                $this->add($this->ioPoll->start($this));
            }

            if (null !== $processResult) {
                $this->processResultCallback = $processResult;
            }

            // Case the process result is not provided
            $done = $processResult ?? $this->processResultCallback;

            // Start the process pool
            while (!$this->queue->isEmpty()) {
                if ($this->paused) {
                    // We break from the scheduler without and keep the current state
                    // of the taskQueue so that restating the queue 
                    break;
                }
                if ($this->stopped) {
                    // We break from the loop and reset the taskQueue so that any subsequent
                    // call to start the scheduler does not block on all task queue
                    break;
                }
                /**
                 * @var Process $process
                 */
                $process = $this->queue->dequeue();
                $channel = $process->run();
                if ($channel instanceof SysCall) {
                    // Handle exception during system calls
                    try {
                        $channel($process, $this, $this->ioPoll);
                    } catch (\Throwable $e) {
                        $process->throw($e);
                        $this->schedule($process);
                    }
                    continue;
                }
                if (!$process->completed()) {
                    $this->schedule($process);
                    continue;
                }
                if (null !== $done) {
                    $done($process->id(), $process->getReturn());
                }
                // kill the process if the process is completed
                $this->kill($process->id());
            }
        }

        public function stop()
        {
            $this->stopped = true;
            $this->stopIO();
            // Flush the processes queue
            $this->flush();

            // Returns true to indicate a successfull stop
            return true;
        }

        public function pause()
        {
            $this->paused = true;
            $this->stopped = false;
            // Stop the io poll
            $this->stopIO();

            // Returns true to indicate a successfull pause
            return true;
        }

        public function resume()
        {
            if (!$this->stopped) {
                $this->paused = false;
                $this->start();
            }
        }

        /**
         * Stop any io event poll running
         * 
         * @return void 
         */
        public function stopIO()
        {
            if ($this->ioPoll) {
                $this->ioPoll->stop();
            }
        }
        
        private function flush()
        {
            $this->queue->clear();
        }

    };
}

/**
 * Create a coroutine object that communicate with the scheduler
 * and perform a given action. Action to be performed is passed
 * as PHP callable or `Generator`.
 * 
 * @param int|string $id
 * @param callable|\Generator $subroutine
 * @return Process
 * @throws Throwable 
 */
function process($id, $subroutine)
{
    return new class($id, $subroutine) implements Process
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
 * This factory function create an event and (I/O) loop platform for running lightweight PHP subroutine using
 * PHP generator API.
 * 
 * `createSocket($socketId)` function uses these call to turn io `fwrite` and `fread` operation to subroutine
 * therefore when performing io operation that might run in the `scheduler`, wrap your
 * socket resources using `createSocket` for benefic performance improvement.
 * 
 */
/**
 * 
 * @return array<\Closure,\Closure,\Closure>
 */
function scheduler()
{
    /**
     * @var ProcessLoop
     */
    $processPoll = createProcessLoop(true);

    return [
        function (callable $processResult = null) use ($processPoll) {
            $processPoll->start($processResult);
        },
        \Closure::fromCallable([$processPoll, 'stop'])->bindTo($processPoll),
        \Closure::fromCallable([$processPoll, 'add'])->bindTo($processPoll),
        \Closure::fromCallable([$processPoll, 'resume'])->bindTo($processPoll),
    ];
};
