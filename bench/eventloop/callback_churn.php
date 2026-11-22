<?php

declare(strict_types=1);

/*
 * Event loop driver benchmark: cost of enabling and disabling a stream callback while the loop ticks.
 *
 *   php bench/eventloop/callback_churn.php [--driver=select,poll] [--iterations=100000] [--runs=3] [--assertions=-1]
 *
 * One always-writable socket carries one writable callback. Two modes, each run in a fresh process per driver:
 *
 *   steady: the callback just counts; its watcher never changes, so a tick costs one wait plus one dispatch.
 *   churn:  the callback disables itself and enables itself again on every call. The loop applies the disable at
 *           once (deactivate) and the enable at the start of the next tick (activate), so every tick carries one
 *           deactivate plus one activate for the same stream.
 *
 * The churn cost per toggle is (churn ns per callback) - (steady ns per callback). A driver that calls the kernel on
 * every activate and deactivate pays two kernel calls per toggle; a driver that collects interest changes per tick
 * and applies the net change before the wait pays none here, since the interest is the same at the next wait.
 * `poll` is PollDriver on Io\Poll's default backend (kqueue or epoll), `select` is StreamSelectDriver. Children run with
 * zend.assertions=--assertions (default -1: production mode, assert() compiled away). Each figure is the median of
 * --runs processes; min and max of the runs are printed next to it.
 */

use Fledge\Loop as EventLoop;
use Fledge\Loop\DriverFactory;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

$options = ['driver' => 'select,poll', 'iterations' => '100000', 'runs' => '3', 'assertions' => '-1', 'child' => null, 'mode' => null];

foreach (\array_slice($argv, 1) as $argument) {
    if (\preg_match('/^--([a-z]+)(?:=(.*))?$/', $argument, $match)) {
        $options[$match[1]] = $match[2] ?? '1';
    }
}

$iterations = (int) $options['iterations'];

if ($options['child'] !== null) {
    $name = $options['driver'];
    $driver = new DriverFactory()->create($name);
    EventLoop::setDriver($driver);

    [$stream, $peer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
    \stream_set_blocking($stream, false);
    $calls = 0;
    $churn = $options['mode'] === 'churn';

    EventLoop::onWritable($stream, static function (string $id) use (&$calls, $iterations, $churn): void {
        if (++$calls >= $iterations) {
            EventLoop::cancel($id);

            return;
        }

        if ($churn) {
            EventLoop::disable($id);
            EventLoop::enable($id);
        }
    });

    $start = \hrtime(true);
    EventLoop::run();
    $elapsed = \hrtime(true) - $start;

    echo \json_encode(['ns_per_call' => $elapsed / $calls, 'class' => $driver::class]), "\n";

    exit(0);
}

\printf(
    "PHP %s %s, zend.assertions=%s, %d iterations, %d run(s), load %.2f\n",
    \PHP_VERSION,
    \PHP_OS_FAMILY,
    $options['assertions'],
    $iterations,
    (int) $options['runs'],
    (\sys_getloadavg() ?: [0.0])[0],
);
\printf("%-8s %22s %22s %16s\n", 'driver', 'steady ns/call', 'churn ns/call', 'ns per toggle');

foreach (\array_filter(\array_map('trim', \explode(',', $options['driver']))) as $name) {
    $medians = [];
    $ranges = [];

    foreach (['steady', 'churn'] as $mode) {
        $samples = [];

        for ($run = 0; $run < (int) $options['runs']; $run++) {
            $output = \shell_exec(\sprintf(
                '%s -d memory_limit=-1 -d zend.assertions=%s %s --child --driver=%s --mode=%s --iterations=%d',
                \escapeshellarg(\PHP_BINARY),
                \escapeshellarg($options['assertions']),
                \escapeshellarg(__FILE__),
                \escapeshellarg($name),
                $mode,
                $iterations,
            ));
            $samples[] = \json_decode((string) $output, true)['ns_per_call'] ?? \NAN;
        }

        \sort($samples);
        $medians[$mode] = $samples[\intdiv(\count($samples), 2)];
        $ranges[$mode] = \sprintf('%.0f (%.0f-%.0f)', $medians[$mode], $samples[0], $samples[\count($samples) - 1]);
    }

    \printf("%-8s %22s %22s %16.0f\n", $name, $ranges['steady'], $ranges['churn'], $medians['churn'] - $medians['steady']);
}
