<?php

declare(strict_types=1);

use Fledge\Loop;
use Fledge\Loop\Driver;
use Fledge\Loop\Driver\PollDriver;
use Fledge\Loop\Driver\StreamSelectDriver;
use Fledge\Loop\Tests\Fixtures\DecoratingDriver;
use Time\Duration;

/*
 * delay() and repeat() take a Time\Duration next to float seconds. A Duration is converted to float seconds once, at
 * the entry point; a negated one raises the same \Error as a negative float. Float INF stays the timer that never
 * fires, since a Duration has no infinity.
 */

dataset('duration drivers', [
    'select' => [static fn (): Driver => new StreamSelectDriver()],
    'poll' => [static fn (): Driver => new PollDriver()],
    'decorated' => [static fn (): Driver => new DecoratingDriver(new StreamSelectDriver())],
]);

it('fires a Duration delay after at least that long, in order with float timers', function (Closure $create) {
    $driver = $create();
    $order = [];
    $start = hrtime(true);
    $elapsed = null;

    $driver->delay(0.04, static function () use (&$order): void {
        $order[] = 'float 40 ms';
    });
    $driver->delay(Duration::fromMilliseconds(20), static function () use (&$order, &$elapsed, $start): void {
        $order[] = 'duration 20 ms';
        $elapsed = (hrtime(true) - $start) / 1e9;
    });
    $driver->delay(0.001, static function () use (&$order): void {
        $order[] = 'float 1 ms';
    });
    $driver->run();

    expect($order)->toBe(['float 1 ms', 'duration 20 ms', 'float 40 ms'])
        ->and($elapsed)->toBeGreaterThanOrEqual(0.0195);
})->with('duration drivers');

it('repeats at a Duration interval', function (Closure $create) {
    $driver = $create();
    $runs = 0;
    $start = hrtime(true);

    $driver->repeat(Duration::fromMilliseconds(5), static function (string $id) use ($driver, &$runs): void {
        if (++$runs === 3) {
            $driver->cancel($id);
        }
    });
    $driver->run();

    expect($runs)->toBe(3)
        ->and((hrtime(true) - $start) / 1e9)->toBeGreaterThanOrEqual(0.0145);
})->with('duration drivers');

it('runs a zero Duration like a zero delay', function (Closure $create, Duration $duration) {
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
})->with('duration drivers')->with([
    'zero' => [Duration::fromSeconds(0)],
    'negated zero' => [Duration::fromSeconds(0)->negate()],
    'one nanosecond' => [Duration::fromNanoseconds(1)],
]);

it('rejects a negated Duration with the negative delay error', function (Closure $create, string $method, string $message) {
    $driver = $create();

    expect(fn () => $driver->{$method}(Duration::fromNanoseconds(1)->negate(), static fn () => null))
        ->toThrow(Error::class, $message)
        ->and(fn () => $driver->{$method}(Duration::fromSeconds(5)->negate(), static fn () => null))
        ->toThrow(Error::class, $message)
        ->and($driver->getIdentifiers())->toBe([]);
})->with('duration drivers')->with([
    'delay' => ['delay', 'Delay must be greater than or equal to zero'],
    'repeat' => ['repeat', 'Interval must be greater than or equal to zero'],
]);

it('still takes float INF as the timer that never fires', function (Closure $create, string $method) {
    $driver = $create();
    $fired = [];

    $never = $driver->{$method}(INF, static function () use (&$fired): void {
        $fired[] = 'INF';
    });
    $driver->unreference($never);
    $driver->delay(Duration::fromMilliseconds(1), static function () use (&$fired): void {
        $fired[] = 'duration';
    });
    $driver->run();

    expect($fired)->toBe(['duration'])
        ->and($driver->getIdentifiers())->toBe([$never]);
})->with('duration drivers')->with(['delay', 'repeat']);

it('keeps float and int seconds working as before', function (Closure $create) {
    $driver = $create();
    $order = [];

    $driver->delay(0.003, static function () use (&$order): void {
        $order[] = 'float';
    });
    $driver->delay(0, static function () use (&$order): void {
        $order[] = 'int';
    });
    $driver->run();

    expect($order)->toBe(['int', 'float'])
        ->and(fn () => $driver->delay(-0.001, static fn () => null))
        ->toThrow(Error::class, 'Delay must be greater than or equal to zero')
        ->and(fn () => $driver->repeat(NAN, static fn () => null))
        ->toThrow(Error::class, 'Interval must be a number, NAN given');
})->with('duration drivers');

it('takes a Duration through the Loop facade', function () {
    $fired = [];

    Loop::delay(Duration::fromMilliseconds(1), static function () use (&$fired): void {
        $fired[] = 'delay';
    });
    Loop::repeat(Duration::fromMilliseconds(1), static function (string $id) use (&$fired): void {
        $fired[] = 'repeat';
        Loop::cancel($id);
    });
    Loop::run();

    sort($fired);

    expect($fired)->toBe(['delay', 'repeat'])
        ->and(fn () => Loop::delay(Duration::fromMilliseconds(1)->negate(), static fn () => null))
        ->toThrow(Error::class, 'Delay must be greater than or equal to zero');
});
