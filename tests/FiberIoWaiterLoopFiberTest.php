<?php

use Fledge\Loop\FiberIoWaiter;
use Fledge\Loop;

/**
 * Enables fiberio with the real FiberIoWaiter behind a decorator that counts
 * timer waits (null stream, events 0), runs $body, and returns the count and
 * the user CPU time it took.
 *
 * @return array{0: mixed, 1: int, 2: float}
 */
function runWithCountedFiberIoWaiter(Closure $body): array
{
    if (\FiberIo\enabled()) {
        \FiberIo\disable();
    }

    $real = new FiberIoWaiter;
    $timerCalls = 0;

    \FiberIo\enable(function ($stream, int $events, ?float $timeout) use ($real, &$timerCalls): bool {
        if ($stream === null || $events === 0) {
            $timerCalls++;
        }

        return $real($stream, $events, $timeout);
    }, \FiberIo\HOOK_ALL);

    try {
        $before = getrusage();
        $result = $body();
        $after = getrusage();
    } finally {
        \FiberIo\disable();
    }

    $cpu = ($after['ru_utime.tv_sec'] - $before['ru_utime.tv_sec'])
        + ($after['ru_utime.tv_usec'] - $before['ru_utime.tv_usec']) / 1e6;

    return [$result, $timerCalls, $cpu];
}

beforeEach(function () {
    if (! extension_loaded('fiberio') || ! defined('FiberIo\\HOOK_SLEEP')) {
        $this->markTestSkipped('needs php-fiberio >= 0.2 (HOOK_SLEEP)');
    }
});

/**
 * With HOOK_SLEEP on, fiberio also hooks the usleep() the event loop's own fiber
 * makes while it idles until the next timer. The waiter must not suspend the
 * loop fiber on itself (that tripped the loop's assertion and hung the process
 * with assertions off), and must not report the wait as woken either (that
 * ended every idle usleep() at once and busy-spun the loop at 100% CPU).
 */
it('lets the event loop fiber idle on timers with the sleep hook on', function () {
    [$results, $timerCalls, $cpu] = runWithCountedFiberIoWaiter(function () {
        $start = hrtime(true);

        $suspension = Loop::getSuspension();
        $values = [];
        $pending = 2;
        $finish = static function (string $value) use (&$values, &$pending, $suspension): void {
            $values[] = $value;

            if (--$pending === 0) {
                $suspension->resume();
            }
        };

        Loop::queue(static function () use ($finish): void {
            usleep(100_000);
            $finish('usleep');
        });
        Loop::queue(static function () use ($finish): void {
            $inner = Loop::getSuspension();
            Loop::delay(0.1, static fn () => $inner->resume());
            $inner->suspend();
            $finish('delay');
        });

        $suspension->suspend();
        $results = $values;

        return [$results, (hrtime(true) - $start) / 1e9];
    });

    [$values, $elapsed] = $results;

    expect($values)->toEqualCanonicalizing(['usleep', 'delay'])
        ->and($elapsed)->toBeGreaterThanOrEqual(0.09)
        ->and($elapsed)->toBeLessThan(0.5)
        ->and($timerCalls)->toBeLessThanOrEqual(10)
        ->and($cpu)->toBeLessThan(0.05);
});

it('does not busy-spin the loop fiber while it idles until a timer', function () {
    [, $timerCalls, $cpu] = runWithCountedFiberIoWaiter(function () {
        Loop::delay(0.1, static fn () => null);
        Loop::run();
    });

    expect($timerCalls)->toBeLessThanOrEqual(4)
        ->and($cpu)->toBeLessThan(0.05);
});
