<?php

declare(strict_types=1);

use Fledge\Loop\Tests\Fixtures\HookedDriver;

/*
 * tick() takes its enable queue before it calls into the driver, so an enable() or defer() issued by user code that
 * runs mid-tick (a signal handler run asynchronously, a stream wrapper destructor reached from a driver call) lands in
 * the next tick's queue instead of being dropped. That same code may disable, re-enable or cancel a callback that is
 * already in the taken queue. Whatever happens, a callback must be active in the driver at most once (for a timer: one
 * heap entry), must run exactly once when it cancels itself, and must not run at all when cancelled.
 *
 * HookedDriver runs the "handler" at one of three call points: on entry to activate(), between two activations, or
 * inside deactivate() of a decoy callback that the handler-at-entry disabled (the deactivation tick() performs for a
 * taken callback found disabled).
 */

/**
 * Arms $action at $point. The decoy is registered right after the target, so "between" runs after the target's
 * activation and "inside deactivate()" runs after the target was reconciled.
 */
function armHandler(HookedDriver $driver, string $point, string $decoy, Closure $action): void
{
    match ($point) {
        'activate' => $driver->hookActivate($action),
        'between' => $driver->hookBetweenActivations($action),
        'deactivate' => (static function () use ($driver, $decoy, $action): void {
            // The first deactivate() is disable()'s own (the decoy is taken, not queued), the second is tick()'s.
            $driver->hookActivate(static fn () => $driver->disable($decoy));
            $driver->hookDeactivate($decoy, 2, $action);
        })(),
    };
}

/**
 * Registers the target from a deferred callback (so it is in the enable queue the next tick takes), arms the handler
 * and runs the loop.
 *
 * @param Closure(HookedDriver, Closure():void): string $register Registers the target; the closure passed counts a run.
 * @param Closure(HookedDriver, string): void $handler What the handler does to the target.
 *
 * @return array{runs: int, driver: HookedDriver, target: string}
 */
function runWithHandler(string $point, Closure $register, Closure $handler): array
{
    $driver = new HookedDriver();
    $runs = 0;
    $target = '';

    $driver->defer(static function () use ($driver, $point, $register, $handler, &$runs, &$target): void {
        $target = $register($driver, static function () use (&$runs): void {
            ++$runs;
        });
        $decoy = $driver->unreference($driver->delay(10, static fn () => null));
        // A second timer, so the taken queue holds two callbacks even when the target is a deferred callback.
        $driver->unreference($driver->delay(10, static fn () => null));

        armHandler($driver, $point, $decoy, static fn () => $handler($driver, $target));
    });

    // Watchdog: ends the loop if a callback were never cancelled.
    $driver->unreference($driver->delay(2, static fn () => $driver->stop()));

    $driver->run();

    // The handler ran (inside deactivate() it is the second hook, after the one that disables the decoy).
    expect($driver->hooksRun)->toBe($point === 'deactivate' ? 2 : 1);

    return ['runs' => $runs, 'driver' => $driver, 'target' => $target];
}

function expectConsistent(HookedDriver $driver, string $target): void
{
    expect($driver->duplicates)->toBe([])
        ->and($driver->active)->not->toHaveKey($target)
        ->and($driver->isTimerQueued($target))->toBeFalse()
        // Left in the heap: at most the two unreferenced decoys and the watchdog, each once.
        ->and($driver->queuedTimerCount())->toBeLessThanOrEqual(3);
}

$points = ['on entry to activate()' => 'activate', 'between two activations' => 'between', 'inside deactivate()' => 'deactivate'];

$selfCancelling = [
    'a timer' => static fn (HookedDriver $d, Closure $ran): string => $d->delay(0, static function () use ($ran): void {
        $ran();
    }),
    'a repeating timer' => static fn (HookedDriver $d, Closure $ran): string => $d->repeat(0, static function (string $id) use ($d, $ran): void {
        $ran();
        $d->cancel($id);
    }),
    'a stream callback' => static fn (HookedDriver $d, Closure $ran): string => $d->onReadable(\fopen('php://memory', 'r'), static function (string $id) use ($d, $ran): void {
        $ran();
        $d->cancel($id);
    }),
    'a signal callback' => static fn (HookedDriver $d, Closure $ran): string => $d->onSignal(\SIGUSR1, static function (string $id) use ($d, $ran): void {
        $ran();
        $d->cancel($id);
    }),
    'a deferred callback' => static fn (HookedDriver $d, Closure $ran): string => $d->defer(static function () use ($ran): void {
        $ran();
    }),
];

foreach ($points as $pointName => $point) {
    describe("a handler run {$pointName}", function () use ($point, $selfCancelling): void {
        it('disables and re-enables a taken callback: it is active once at a time and runs exactly once', function (Closure $register) use ($point): void {
            $result = runWithHandler($point, $register, static function (HookedDriver $d, string $target): void {
                $d->disable($target);
                $d->enable($target);
            });

            expect($result['runs'])->toBe(1);
            expectConsistent($result['driver'], $result['target']);
        })->with($selfCancelling);

        it('cancels a taken callback: it never runs', function (Closure $register) use ($point): void {
            $result = runWithHandler($point, $register, static function (HookedDriver $d, string $target): void {
                $d->cancel($target);
            });

            expect($result['runs'])->toBe(0);
            expectConsistent($result['driver'], $result['target']);
        })->with($selfCancelling);

        it('enables a disabled callback: it is not dropped and runs exactly once', function () use ($point): void {
            $enabledRuns = 0;
            $disabled = null;

            $result = runWithHandler(
                $point,
                static function (HookedDriver $d, Closure $ran) use (&$disabled, &$enabledRuns): string {
                    $disabled = $d->disable($d->delay(0, static function () use (&$enabledRuns): void {
                        ++$enabledRuns;
                    }));

                    return $d->delay(0, static function () use ($ran): void {
                        $ran();
                    });
                },
                static function (HookedDriver $d) use (&$disabled): void {
                    $d->enable($disabled);
                },
            );

            expect($result['runs'])->toBe(1)
                ->and($enabledRuns)->toBe(1);
            expectConsistent($result['driver'], $result['target']);
            expectConsistent($result['driver'], $disabled);
        });

        it('defers a new callback: it is not dropped and runs exactly once', function () use ($point): void {
            $deferredRuns = 0;

            $result = runWithHandler(
                $point,
                static fn (HookedDriver $d, Closure $ran): string => $d->delay(0, static function () use ($ran): void {
                    $ran();
                }),
                static function (HookedDriver $d) use (&$deferredRuns): void {
                    $d->defer(static function () use (&$deferredRuns): void {
                        ++$deferredRuns;
                    });
                },
            );

            expect($result['runs'])->toBe(1)
                ->and($deferredRuns)->toBe(1);
            expectConsistent($result['driver'], $result['target']);
        });
    });
}

it('fires a re-enabled taken timer at its new expiration, once', function () {
    $driver = new HookedDriver();
    $firedAt = [];
    $start = \hrtime(true);

    $driver->defer(static function () use ($driver, &$firedAt, $start): void {
        $timer = $driver->delay(0.05, static function () use (&$firedAt, $start): void {
            $firedAt[] = (\hrtime(true) - $start) / 1e9;
        });

        // Between activations: the timer is already in the heap when the handler moves it.
        $driver->delay(10, static fn () => null) |> $driver->unreference(...);
        $driver->hookBetweenActivations(static function () use ($driver, $timer): void {
            \usleep(30_000);
            $driver->disable($timer);
            $driver->enable($timer); // expires 50 ms from now, 80 ms after start
        });
    });

    $driver->run();

    expect($firedAt)->toHaveCount(1)
        ->and($firedAt[0])->toBeGreaterThanOrEqual(0.08)
        ->and($driver->duplicates)->toBe([]);
});
