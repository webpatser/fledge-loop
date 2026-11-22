<?php

declare(strict_types=1);

use Fledge\Loop\Driver;
use Fledge\Loop\Driver\PollDriver;
use Fledge\Loop\Driver\StreamSelectDriver;
use Fledge\Loop\FiberIoWaiter;

/*
 * Durations that enter the timer API: delay() and repeat() accept zero or more seconds (-0.0 is zero, INF is a timer
 * that never fires) and reject negative values and NAN, which fails every comparison and would corrupt the order of
 * the timer heap.
 */

dataset('timer drivers', [
    'select' => [static fn (): Driver => new StreamSelectDriver()],
    'poll' => [static fn (): Driver => new PollDriver()],
]);

it('rejects a NAN or negative delay and interval', function (Closure $create, string $method, float $duration, string $message) {
    $driver = $create();

    expect(fn () => $driver->{$method}($duration, static fn () => null))->toThrow(Error::class, $message)
        ->and($driver->getIdentifiers())->toBe([]);
})->with('timer drivers')->with([
    'delay NAN' => ['delay', NAN, 'Delay must be a number, NAN given'],
    'delay negative' => ['delay', -0.001, 'Delay must be greater than or equal to zero'],
    'delay -INF' => ['delay', -INF, 'Delay must be greater than or equal to zero'],
    'repeat NAN' => ['repeat', NAN, 'Interval must be a number, NAN given'],
    'repeat negative' => ['repeat', -1.0, 'Interval must be greater than or equal to zero'],
]);

it('runs a -0.0 and a subnormal delay like a zero delay', function (Closure $create, float $duration) {
    $driver = $create();
    $order = [];

    $driver->delay(0.002, static function () use (&$order): void {
        $order[] = 'later';
    });
    $driver->delay($duration, static function () use (&$order): void {
        $order[] = 'now';
    });
    $driver->run();

    expect($order)->toBe(['now', 'later']);
})->with('timer drivers')->with([
    '-0.0' => [-0.0],
    'subnormal' => [5e-324],
]);

it('repeats a -0.0 and a subnormal interval every tick', function (Closure $create, float $duration) {
    $driver = $create();
    $runs = 0;

    $driver->repeat($duration, static function (string $id) use ($driver, &$runs): void {
        if (++$runs === 3) {
            $driver->cancel($id);
        }
    });
    $driver->run();

    expect($runs)->toBe(3);
})->with('timer drivers')->with([
    '-0.0' => [-0.0],
    'subnormal' => [5e-324],
]);

it('never fires an INF timer and keeps the others in order', function (Closure $create, string $method) {
    $driver = $create();
    $fired = [];

    $infinite = $driver->{$method}(INF, static function () use (&$fired): void {
        $fired[] = 'inf';
    });

    foreach ([0.03, 0.01, 0.02] as $delay) {
        $driver->delay($delay, static function () use (&$fired, $delay): void {
            $fired[] = $delay;
        });
    }

    $driver->delay(0.05, static fn () => $driver->cancel($infinite));
    $started = hrtime(true);
    $driver->run();

    expect($fired)->toBe([0.01, 0.02, 0.03])
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0);
})->with('timer drivers')->with(['delay', 'repeat']);

it('keeps timers in order after a NAN duration was rejected', function (Closure $create) {
    $driver = $create();
    $delays = [0.012, 0.004, 0.02, 0.008, 0.016, 0.001];
    $fired = [];

    foreach ($delays as $index => $delay) {
        if ($index === 3) {
            expect(fn () => $driver->repeat(NAN, static fn () => null))->toThrow(Error::class);
        }

        $driver->delay($delay, static function () use (&$fired, $delay): void {
            $fired[] = $delay;
        });
    }

    $driver->run();
    $sorted = $delays;
    sort($sorted);

    expect($fired)->toBe($sorted);
})->with('timer drivers');

it('rejects a NAN timeout in FiberIoWaiter', function () {
    expect(fn () => (new FiberIoWaiter())(null, 0, NAN))->toThrow(InvalidArgumentException::class, 'NAN given');
});
