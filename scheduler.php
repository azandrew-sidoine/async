<?php

// use function Drewlabs\Async\Scheduler\close;
use function Drewlabs\Async\Scheduler\fork;
use function Drewlabs\Async\Scheduler\kill;
use function Drewlabs\Async\Scheduler\processId;
use function Drewlabs\Async\Scheduler\scheduler;
use function Drewlabs\Async\Scheduler\spawn;
use function Drewlabs\Async\Scheduler\suspend;

// use function Drewlabs\Async\Scheduler\suspend;

require __DIR__ . '/vendor/autoload.php';


list($run, $addProcess, $isEmpty,, $resume) = scheduler();


function childProcess()
{
    $process = (yield processId());
    $count = 0;
    while (true) {
        yield usleep(1000 * 1500);
        printf("Child process %s: Loop %s\n", $process, $count);
        $count++;
    }
}

function mainProcess()
{
    $process = (yield processId());
    $childProcess = (yield spawn(function () {
        return childProcess();
    }));
    $forkedProcess = (yield fork($childProcess));
    printf("Forked: %s -> %s\n", $childProcess, $forkedProcess);
    $forkedProcess2 = (yield fork($childProcess));
    printf("Forked: %s -> %s\n", $childProcess, $forkedProcess2);
    for ($i = 0; $i < 15; $i++) {
        yield usleep(1000 * 1000);
        printf("Main process: %s Loop %s\n", $process, $i);
        if ($i === 5) {
            yield kill($childProcess);
        }
        if (($i === 7) && $forkedProcess) {
            yield kill($forkedProcess);
        }

        if ($i === 8) {
            yield suspend();
        }

        if (($i === 11) && $forkedProcess2) {
            yield kill($forkedProcess2);
        }
    }
}

$addProcess(mainProcess());

// Run the loop
$run();

printf("Process suspended...\n");

usleep(1000 * 2000);
printf("Resuming process after 2 seconds....\n");
// Resume the process
$resume();
