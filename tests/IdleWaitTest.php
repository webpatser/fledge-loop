<?php

declare(strict_types=1);

use Fledge\Loop\Driver\PollDriver;

/*
 * A loop that watches only an idle socket must block in its wait, in every mode and with any timer: with no timer, a
 * timer far away (10 minutes, 1000 years, or at INF, which never fires) or a signal callback. Debug mode has no sweep
 * time to cap the wait at, so "no limit" must stay a null timeout: an INF timeout reaching an int conversion would
 * raise a warning and become a zero timeout, spinning the loop at a full core. A timer years away must not overflow
 * the same conversion (a negative Duration, a ValueError) or make stream_select() fail with EINVAL on macOS.
 *
 * The child process forks one grandchild per driver and scenario before any loop exists; each runs its own loop for
 * about one second, until the child writes to its socket, and reports the CPU time its loop used and every PHP error
 * it raised. A blocking loop uses next to no CPU in that second; a spinning one tens to hundreds of milliseconds. The
 * child is killed (with its grandchildren) after 30 seconds, so a loop that never wakes fails the test.
 */

const IDLE_WAIT_SCENARIO = <<<'PHP'
    $scenarios = ['no timer', 'timer 10 minutes away', 'timer 1000 years away', 'timer at INF', 'signal callback'];
    $children = [];

    foreach (['select', 'poll'] as $name) {
        foreach ($scenarios as $scenario) {
            [$wake, $wakePeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $pid = pcntl_fork();

            if ($pid === 0) {
                fclose($wakePeer);
                $issues = [];
                set_error_handler(static function (int $level, string $message, string $file, int $line) use (&$issues): bool {
                    $issues[] = "{$message} ({$file}:{$line})";

                    return true;
                });

                $driver = $name === 'select' ? new Fledge\Loop\Driver\StreamSelectDriver() : new Fledge\Loop\Driver\PollDriver();
                [$idle, $idlePeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                $ids = [$driver->onReadable($idle, static fn () => null)];
                $ids[] = $driver->onReadable($wake, static fn () => $driver->stop());
                $ids[] = match ($scenario) {
                    'no timer' => null,
                    'timer 10 minutes away' => $driver->delay(600, static fn () => null),
                    'timer 1000 years away' => $driver->delay(1000 * 365 * 86400, static fn () => null),
                    'timer at INF' => $driver->delay(INF, static fn () => null),
                    'signal callback' => $driver->onSignal(SIGUSR2, static fn () => null),
                };

                $cpu = static function (): float {
                    $usage = getrusage();

                    return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
                        + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
                };
                $error = null;
                $cpuBefore = $cpu();
                $start = hrtime(true);

                try {
                    $driver->run();
                } catch (Throwable $exception) {
                    $error = $exception::class.': '.$exception->getMessage();
                }

                $report = [
                    'case' => "{$name}, {$scenario}",
                    'cpu' => $cpu() - $cpuBefore,
                    'elapsed' => (hrtime(true) - $start) / 1e9,
                    'error' => $error,
                    'issues' => array_slice($issues, 0, 3),
                    'issueCount' => count($issues),
                ];

                foreach (array_filter($ids) as $id) {
                    $driver->cancel($id);
                }

                fwrite(STDOUT, json_encode($report)."\n");
                exit(0);
            }

            fclose($wake);
            $children[$pid] = $wakePeer;
        }
    }

    usleep(1_000_000);

    foreach ($children as $pid => $wakePeer) {
        fwrite($wakePeer, 'x');
    }

    foreach ($children as $pid => $wakePeer) {
        pcntl_waitpid($pid, $status);
    }
    PHP;

it('blocks while only an idle socket is watched', function (array $env, array $ini) {
    $result = runPhpSubprocess(IDLE_WAIT_SCENARIO, env: $env, timeout: 30.0, ini: $ini);

    expect($result['timedOut'])->toBeFalse("a loop never woke up; stderr: {$result['stderr']}")
        ->and($result['exit'])->toBe(0, "stdout: {$result['stdout']} stderr: {$result['stderr']}")
        ->and($result['stderr'])->toBe('');

    $reports = array_map(
        static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        array_filter(explode("\n", $result['stdout'])),
    );

    expect($reports)->toHaveCount(10);

    foreach ($reports as $report) {
        $case = $report['case'];

        expect($report['error'])->toBeNull("{$case}: {$report['error']}")
            ->and($report['issueCount'])->toBe(0, "{$case}: ".implode('; ', $report['issues']))
            ->and($report['elapsed'])->toBeGreaterThan(0.5, $case)->toBeLessThan(10.0, $case)
            // A blocking loop wakes a handful of times in that second; a spinning one burns a large share of it.
            ->and($report['cpu'])->toBeLessThan(0.05, "{$case}: {$report['cpu']} s of CPU");
    }
})->with([
    'debug mode (assertions)' => [[PollDriver::DEBUG_ENV => '0'], ['zend.assertions' => '1', 'assert.exception' => '1']],
    'debug mode (FLEDGE_DEBUG)' => [[PollDriver::DEBUG_ENV => '1'], ['zend.assertions' => '0']],
    'production mode' => [[PollDriver::DEBUG_ENV => '0'], ['zend.assertions' => '0']],
])->skip(! function_exists('pcntl_fork'), 'needs pcntl');
