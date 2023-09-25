<?php

use Drewlabs\Async\PromiseInterface;
use PHPUnit\Framework\TestCase;

use function Drewlabs\Async\Future\async;
use function Drewlabs\Async\Future\await;
use function Drewlabs\Async\Future\promiseFactory;

class AsyncTest extends TestCase
{

    public function test_async_resolve()
    {
        $promise = async(function () {
            yield usleep(1000 * 500);
            return 2;
        });
        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $this->assertEquals(2, await($promise));
    }

    public function test_async_reject()
    {
        $throw = false;
        $promise = async(function () use (&$throw) {
            yield usleep(1000 * 500);
            if ($throw) {
                yield new Exception('Expired!');
            }
            return 2;
        });

        $throw = true;

        $promise->catch(function ($e) {
            $this->assertEquals('Expired!', $e->getMessage());
        });

        // Wait for the promise to execute
        $promise->wait();
    }

    public function test_async_then_chain()
    {
        $promise = async(function () {
            usleep(1000 * 500);
            return 'awaited';
        });
        $promise->then(function ($value) {
            return strtoupper($value);
        })->then(function ($value) {
            $this->assertEquals('AWAITED', $value);
        });
        $promise->wait();
    }

    public function test_async_then_return_new_async()
    {
        $nextPromise = promiseFactory();
        $promise = async(function () {
            yield usleep(1000 * 500);
            return 2;
        });

        $promise->then(function ($value) use ($nextPromise) {
            $this->assertEquals(2, $value);
            return $nextPromise;
        })->then(function ($result) {
            $this->assertEquals(3, $result);
        });
        
        $promise->wait();

        $nextPromise->resolve(3);

    }
}
