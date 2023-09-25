<?php

use function Drewlabs\Async\Future\async;
use function Drewlabs\Async\Future\defer;

require __DIR__ . '/vendor/autoload.php';

$promise = async(function () {
    printf("Calling co routine...\n");
    usleep(1000 * 2000);
    return 'awaited';
});

$promise2 = defer(function($resolve) {
    usleep(1000*1000);
    $resolve('Shutting down');
}, true);

$promise2->then(function($note) {
    return sprintf("%s...", $note);
})->then(function($shutdown) {
    printf("Shudown Note: %s\n", $shutdown);
});

$promise->wait();

printf("Before promise then...\n");

$promise->then(function ($value) {
    return strtoupper($value);
})->then(function ($value) {
    printf("Result: %s\n", $value);
});

printf("Before promise wait...\n");

// Waiting on the promise promise execute the coroutine and pass value to then function
$promise->wait();


$promise = async(function () {
    usleep(1000 * 2000);
    throw new Exception('My Exception');
});

$promise->catch(function($e) {
    printf("Error: %s\n", $e->getMessage());
});

$promise->wait();