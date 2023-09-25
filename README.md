# Async

The `async` library provides a list of utility function to doing basic async programming in PHP language. It makes use of PHP `Generator` to implement a co-routine platform on top of which async tasks are executed.

## Usage

The library provides the following utility functions:

- async `async(callable $waitFn)`

The `async` utility provides an asynchronous function execution context for the subroutine passed as argument.

It returns a `Awaitable` instance which start the subroutine when `wait()` is invoked on it. The `wait()` statement as it will pause the script execution until the subroutine complete.

```php
<?php 
$promise = async(function () {
	printf("Calling coroutine...\n");
	usleep(1000 * 2000);
	return 'awaited';
});
$promise->then(function($value) {
	printf("%s...", $value); // awaited...
});
```

- promise `promise(callable $waitFn, bool $shutdown = false)`

  `promise` provides a factory function that create promise A+ specification instance.
  It takes a waitable function with a reference to the `revolve` function as first param and a reference to the `reject` function of the promise instance as second parameter.

  ```php
  $promise = promise(function($resolve, $reject) {
       // Do something with resolve
       usleep(1000*1000); // Block PHP execution
       resolve("I have been waited for 1 second.");
  });
  // Wait for the promise
  $promise->wait();
  ```

  **Note** In the above example, without calling `wait()`, the promise
  coroutine to execute, does not run.
  To create a promise that automatically run on PHP process shutdown, the
  factory function takes a second boolean flag as parameter.

  ```php
  // Here the script create a promise instance that executes when
  // PHP process shutdown with success
  $promise = promise(function($resolve, $reject) {
       // Do something with resolve
       usleep(1000*1000); // Block PHP execution
       resolve("I have been waited for 1 second.");
  }, true);
  ```
- defer `defer(callable $waitFn)`
  `defer` creates a promise instance that executes resolve and reject callbacks when on ` PHP` process shutdown. It's the simply version of  `promise($waitFn, true)`

```php

// Here the script create a promise instance that executes when
// PHP process shutdown with success

$promise = defer(function($resolve, $reject) {
     // Do something with resolve
     usleep(1000*1000); // Block PHP execution
     resolve("I have been waited for 1 second.");
});

```

- join `join(...$waitFn) `

`join`, is same as `async` interface, except the fact that is takes a list of subroutines wait on the result of those subroutines and return the a list of the awaited result in the order they were inserted.

```php

$promise = join(
    function () {
        printf("Calling coroutine...\n");
        yield usleep(1000*2000); // blocking
        return'awaited';
    },
    function () {
        printf("Calling second coroutine...\n");
        yield usleep(1000*2000); // blocking
        return'awaited 2';
    },
);


$promise->then(function($value) {
     print_r($value); // ['awaited', 'awaited 2']
});
// ...
// Start the async routine
$promise->wait();

```

- await & all `await($coroutine) / all(array $coroutines) `
  These are utility function for waiting on `async` and `join` subroutines respectively.

  ```php
  $promise = async(function () {
       yield usleep(1000 * 500);
       return 2;
  });

  // ...
  // Awaiting the coroutine
  $result = await($promise);
  printf("%d\n", $result); // 2 

  // Developpers can directly call / await on a given subroutine
  $result2 = await(function () {
       yield usleep(1000 * 500);
       return 2;
  }); 

  printf("%d\n", $result2); // 2 
  ```

  `all` works the same as `await` except that is takes an array of promises or subroutines and returns an array of resolved values.
