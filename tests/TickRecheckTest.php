<?php

declare(strict_types=1);

use Fledge\Loop\Driver\PollDriver;

/*
 * A tick decides whether its wait may block before user code runs in it: the callbacks a blocking tick runs first,
 * and the signal handlers pcntl_signal_dispatch() runs in a driver's dispatch(). When that code stops the loop,
 * cancels the last referenced callback or schedules a timer due sooner than the wait's timeout, the wait must not
 * block on the out-of-date decision; if it did, run() would return only at the next wake (in production PollDriver's
 * once-per-second sweep, otherwise never while nothing else woke the loop).
 *
 * Each scenario runs in a grandchild forked before any loop exists, with only an idle socket watched and no timer. It
 * reports readiness from a deferred callback queued by a deferred callback, so the loop has run a full tick, and the
 * child sends SIGUSR1 100 ms later, while the loop blocks in its kernel wait. The handler either runs asynchronously
 * (pcntl_async_signals(true), right after the interrupted wait) or is dispatched by the driver's own
 * pcntl_signal_dispatch() at the next tick. Watchdog: 3 s after the signal the child writes to an unreferenced socket
 * the grandchild watches, which ends a loop that would otherwise hang (and is reported as hung); the whole child is
 * killed after 60 s.
 */

const TICK_RECHECK_SCENARIO = <<<'PHP'
    $scenarios = [
        'async handler defers stop()',
        'dispatched handler calls stop()',
        'async handler defers cancelling the last referenced callback',
        'dispatched handler cancels the last referenced callback',
        'async handler defers a callback adding a 10 ms timer',
        'dispatched handler adds a 10 ms timer',
    ];
    pcntl_signal(SIGPIPE, SIG_IGN);
    $children = [];

    foreach ($scenarios as $scenario) {
        [$ready, $readyPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        [$wake, $wakePeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $pid = pcntl_fork();

        if ($pid === 0) {
            fclose($readyPeer);
            fclose($wakePeer);
            pcntl_async_signals(str_starts_with($scenario, 'async'));

            $driver = $name === 'select' ? new Fledge\Loop\Driver\StreamSelectDriver() : new Fledge\Loop\Driver\PollDriver();
            [$idle, $idlePeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $idleId = $driver->onReadable($idle, static fn () => null);
            $hung = false;
            $wakeId = $driver->unreference($driver->onReadable($wake, static function () use ($driver, &$hung): void {
                $hung = true;
                $driver->stop();
            }));

            $signalled = null;
            $fired = null;
            $timer = static function () use ($driver, &$fired): void {
                $fired = hrtime(true);
                $driver->stop();
            };
            $act = match ($scenario) {
                'async handler defers stop()' => static function () use ($driver): void {
                    $driver->defer(static function () use ($driver): void {
                        $driver->stop();
                    });
                },
                'dispatched handler calls stop()' => static function () use ($driver): void {
                    $driver->stop();
                },
                'async handler defers cancelling the last referenced callback' => static function () use ($driver, $idleId): void {
                    $driver->defer(static function () use ($driver, $idleId): void {
                        $driver->cancel($idleId);
                    });
                },
                'dispatched handler cancels the last referenced callback' => static function () use ($driver, $idleId): void {
                    $driver->cancel($idleId);
                },
                'async handler defers a callback adding a 10 ms timer' => static function () use ($driver, $timer): void {
                    $driver->defer(static function () use ($driver, $timer): void {
                        $driver->delay(0.01, $timer);
                    });
                },
                'dispatched handler adds a 10 ms timer' => static function () use ($driver, $timer): void {
                    $driver->delay(0.01, $timer);
                },
            };

            // No SA_RESTART: the signal must interrupt the kernel wait in both handler modes.
            pcntl_signal(SIGUSR1, static function () use (&$signalled, $act): void {
                $signalled ??= hrtime(true);
                $act();
            }, false);

            $driver->defer(static function () use ($driver, $ready): void {
                $driver->defer(static function () use ($ready): void {
                    fwrite($ready, 'r');
                });
            });

            $error = null;

            try {
                $driver->run();
            } catch (Throwable $exception) {
                $error = $exception::class.': '.$exception->getMessage();
            }

            $returned = hrtime(true);
            $timerCase = str_ends_with($scenario, 'timer');

            fwrite(STDOUT, json_encode([
                'case' => "{$name}, {$scenario}",
                'error' => $error,
                'signalled' => $signalled !== null,
                'hung' => $hung,
                // Seconds from the handler to run() returning; for a timer, how late it fired.
                'latency' => $signalled === null ? null : (
                    $timerCase
                        ? ($fired === null ? null : ($fired - $signalled) / 1e9 - 0.01)
                        : ($returned - $signalled) / 1e9
                ),
            ])."\n");

            foreach ($driver->getIdentifiers() as $id) {
                $driver->cancel($id);
            }

            exit(0);
        }

        fclose($ready);
        fclose($wake);
        $children[$pid] = [$readyPeer, $wakePeer];
    }

    foreach ($children as $pid => [$readyPeer]) {
        stream_set_timeout($readyPeer, 10);
        fread($readyPeer, 1);
    }

    usleep(100_000);

    foreach ($children as $pid => $_) {
        posix_kill($pid, SIGUSR1);
    }

    $deadline = hrtime(true) + 3_000_000_000;
    $woken = false;

    while ($children !== []) {
        foreach ($children as $pid => $_) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                unset($children[$pid]);
            }
        }

        if (!$woken && hrtime(true) > $deadline) {
            $woken = true;

            foreach ($children as [, $wakePeer]) {
                @fwrite($wakePeer, 'x');
            }
        }

        usleep(1000);
    }
    PHP;

it('does not block on a decision that user code in the tick made out of date', function (string $name, array $env, array $ini) {
    $result = runPhpSubprocess('$name = '.var_export($name, true).";\n".TICK_RECHECK_SCENARIO, env: $env, timeout: 60.0, ini: $ini);

    expect($result['timedOut'])->toBeFalse("stderr: {$result['stderr']}")
        ->and($result['exit'])->toBe(0, "stdout: {$result['stdout']} stderr: {$result['stderr']}")
        ->and($result['stderr'])->toBe('');

    $reports = array_map(
        static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        array_filter(explode("\n", $result['stdout'])),
    );

    expect($reports)->toHaveCount(6);

    foreach ($reports as $report) {
        $case = $report['case'];

        expect($report['error'])->toBeNull("{$case}: {$report['error']}")
            ->and($report['signalled'])->toBeTrue("{$case}: the handler never ran")
            ->and($report['hung'])->toBeFalse("{$case}: run() returned only when the watchdog woke the loop")
            // Healthy: a few milliseconds. Blocking on the stale decision: until the next wake, about 1 s (sweep or
            // signal cap) or never. The bound sits between the two, so a slow shared CI runner cannot fail it.
            ->and($report['latency'])->not->toBeNull($case)
            ->toBeLessThan(0.35, "{$case}: {$report['latency']} s");
    }
})->with(['select', 'poll'])->with([
    'debug mode' => [[PollDriver::DEBUG_ENV => '0'], ['zend.assertions' => '1', 'assert.exception' => '1']],
    'production mode' => [[PollDriver::DEBUG_ENV => '0'], ['zend.assertions' => '0']],
])->skip(! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'needs pcntl and posix');
