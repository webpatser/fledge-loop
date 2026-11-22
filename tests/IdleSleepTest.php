<?php

declare(strict_types=1);

/*
 * The idle sleep of both drivers: a tick with no stream to wait on sleeps until the next timer, or with only signal
 * callbacks left until a signal arrives (Internal\IdleSleep). usleep() rejects more than UINT32_MAX microseconds with a
 * ValueError, which the original's usleep(PHP_INT_MAX) hit on PHP 8.6, and so did any timer further away than about
 * 71 minutes. Each scenario runs in a child process that pcntl_alarm() signals after one second; the child is killed
 * after 20 seconds, so a sleep that never ends fails the test instead of hanging the suite.
 */

const IDLE_SLEEP_SCENARIO = <<<'PHP'
    $driver = $name === 'select' ? new Fledge\Loop\Driver\StreamSelectDriver() : new Fledge\Loop\Driver\PollDriver();
    $cpu = static function (): float {
        $usage = getrusage();

        return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
            + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
    };
    $events = [];
    $timerId = $withTimer ? $driver->delay(7200, static function () use (&$events): void {
        $events[] = 'timer';
    }) : null;
    $driver->onSignal(SIGALRM, static function (string $id) use ($driver, $timerId, &$events): void {
        $events[] = 'SIGALRM';
        $driver->cancel($id);
        if ($timerId !== null) {
            $driver->cancel($timerId);
        }
    });
    pcntl_alarm(1);
    $cpuBefore = $cpu();
    $start = hrtime(true);
    $driver->run();
    echo json_encode([
        'events' => $events,
        'elapsed' => (hrtime(true) - $start) / 1e9,
        'cpu' => $cpu() - $cpuBefore,
    ]);
    PHP;

it('blocks until a signal arrives and then dispatches it', function (string $name, bool $withTimer) {
    $result = runPhpSubprocess(
        '$name = '.var_export($name, true).'; $withTimer = '.var_export($withTimer, true).";\n".IDLE_SLEEP_SCENARIO,
        timeout: 20.0,
    );

    expect($result['timedOut'])->toBeFalse("the idle loop never woke up; stderr: {$result['stderr']}")
        ->and($result['exit'])->toBe(0, "stdout: {$result['stdout']} stderr: {$result['stderr']}");

    $report = json_decode($result['stdout'], true);

    expect($report['events'])->toBe(['SIGALRM'])
        ->and($report['elapsed'])->toBeGreaterThan(0.9)->toBeLessThan(5.0)
        // A busy-waiting loop burns about a second of CPU in that second; a sleeping one next to nothing.
        ->and($report['cpu'])->toBeLessThan(0.2);
})->with(['select', 'poll'])->with([
    'only a signal callback' => false,
    'and a timer two hours away' => true,
])->skip(! function_exists('pcntl_alarm'), 'needs pcntl');
