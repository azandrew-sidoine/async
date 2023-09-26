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

use function Drewlabs\Async\Future\async;
use function Drewlabs\Async\Future\await;

use function Drewlabs\Async\Future\promise;

use Drewlabs\Async\PromiseInterface;
use PHPUnit\Framework\TestCase;

class AsyncTest extends TestCase
{
    public function test_async_resolve()
    {
        $promise = async(static function () {
            yield usleep(1000 * 500);

            return 2;
        });
        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $this->assertSame(2, await($promise));
    }

    public function test_async_reject()
    {
        $throw = false;
        $promise = async(static function () use (&$throw) {
            yield usleep(1000 * 500);
            if ($throw) {
                yield new Exception('Expired!');
            }

            return 2;
        });

        $throw = true;

        $promise->catch(function ($e) {
            $this->assertSame('Expired!', $e->getMessage());
        });

        // Wait for the promise to execute
        $promise->wait();
    }

    public function test_async_then_chain()
    {
        $promise = async(static function () {
            usleep(1000 * 500);

            return 'awaited';
        });
        $promise->then(static function ($value) {
            return strtoupper($value);
        })->then(function ($value) {
            $this->assertSame('AWAITED', $value);
        });
        $promise->wait();
    }

    public function test_async_then_return_new_async()
    {
        $nextPromise = promise();
        $promise = async(static function () {
            yield usleep(1000 * 500);

            return 2;
        });

        $promise->then(function ($value) use ($nextPromise) {
            $this->assertSame(2, $value);

            return $nextPromise;
        })->then(function ($result) {
            $this->assertSame(3, $result);
        });

        $promise->wait();

        $nextPromise->resolve(3);

    }
}
