<?php

declare(strict_types=1);

/*
 * Event loop driver benchmark: the cost of one zero-timeout loop tick.
 *
 *   php bench/eventloop/tick.php [--driver=select,poll] [--watchers=0,1] [--streams=0,1,100,1000,10000]
 *       [--ticks=300000] [--rounds=7] [--runs=1] [--assertions=-1]
 *
 * A deferred callback re-queues itself --ticks times, so every tick is non-blocking: the driver's dispatch runs with
 * a zero timeout, then the one deferred callback. Two setups:
 *
 *   --watchers=W  W idle sockets each carry one (unreferenced) readable callback, so the driver also does a
 *                 zero-timeout wait on its backend (PollDriver) or a zero-timeout stream_select()
 *                 (StreamSelectDriver) per tick; with 0 there is nothing to wait on and the driver skips the wait.
 *   --streams=K   K idle sockets sit in the loop the way stream objects do: each carries an enabled readable callback
 *                 AND a registered but disabled writable callback, both unreferenced (so run() returns when the deferred
 *                 chain ends, and nothing short-cuts a scan for referenced callbacks). Any per-tick
 *                 work that walks all callbacks or all watchers shows up as a cost that grows with K. The default
 *                 list is not run unless --streams is given; K above 1023 needs PollDriver (FD_SETSIZE).
 *
 * With K above 10 a round runs --ticks / (K / 10) ticks (at least 1000), so a round with many streams stays short.
 * Each configuration runs --runs fresh processes of --rounds rounds each; a process reports the median ns per tick of
 * its rounds, and the table shows the median of the runs with the min and max run. Children run with
 * zend.assertions=--assertions (default -1: production mode, assert() compiled away).
 */

use Fledge\Loop as EventLoop;
use Fledge\Loop\DriverFactory;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

$options = [
    'driver' => 'select,poll',
    'watchers' => '0,1',
    'streams' => null,
    'ticks' => '300000',
    'rounds' => '7',
    'runs' => '1',
    'assertions' => '-1',
    'child' => null,
];

foreach (\array_slice($argv, 1) as $argument) {
    if (\preg_match('/^--([a-z]+)(?:=(.*))?$/', $argument, $match)) {
        $options[$match[1]] = $match[2] ?? '1';
    }
}

$ticks = (int) $options['ticks'];
$rounds = (int) $options['rounds'];

if ($options['child'] !== null) {
    $count = (int) ($options['count'] ?? 0);
    $setup = $options['setup'] ?? 'watchers';
    // Large K makes a tick that walks all callbacks slow: keep a round near the same length.
    $ticks = \max(1000, \intdiv($ticks, \max(1, \intdiv($count, 10))));

    $limits = \function_exists('posix_getrlimit') ? \posix_getrlimit(\POSIX_RLIMIT_NOFILE) : false;
    if ($limits !== false && $limits[0] !== 'unlimited' && (int) $limits[0] < 2 * $count + 64) {
        \posix_setrlimit(\POSIX_RLIMIT_NOFILE, 2 * $count + 64, $limits[1] === 'unlimited' ? \POSIX_RLIMIT_INFINITY : (int) $limits[1]);
    }

    EventLoop::setDriver(new DriverFactory()->create($options['driver']));

    $pairs = [];

    for ($i = 0; $i < $count; $i++) {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);

        if ($pair === false) {
            echo \json_encode(['error' => "stream_socket_pair failed at {$i}"]), "\n";
            exit(1);
        }

        $pairs[] = $pair;
        $read = EventLoop::onReadable($pair[0], static fn () => null);

        if ($setup === 'streams') {
            EventLoop::unreference(EventLoop::disable(EventLoop::onWritable($pair[0], static fn () => null)));
        }

        EventLoop::unreference($read);
    }

    $samples = [];

    for ($round = 0; $round < $rounds; $round++) {
        $i = 0;
        $next = static function () use (&$i, $ticks, &$next): void {
            if (++$i < $ticks) {
                EventLoop::defer($next);
            }
        };
        EventLoop::defer($next);

        $start = \hrtime(true);
        EventLoop::run();
        $samples[] = (\hrtime(true) - $start) / $ticks;
    }

    \sort($samples);

    echo \json_encode([
        'median' => $samples[\intdiv(\count($samples), 2)],
        'min' => $samples[0],
        'max' => $samples[\count($samples) - 1],
    ]), "\n";

    exit(0);
}

$configurations = [];

foreach (\array_filter(\array_map('trim', \explode(',', $options['driver']))) as $name) {
    if ($options['watchers'] !== '') {
        foreach (\array_map('intval', \explode(',', $options['watchers'])) as $count) {
            $configurations[] = [$name, 'watchers', $count];
        }
    }

    if ($options['streams'] !== null) {
        $list = $options['streams'] === '1' ? '0,1,100,1000,10000' : $options['streams'];

        foreach (\array_map('intval', \explode(',', $list)) as $count) {
            $configurations[] = [$name, 'streams', $count];
        }
    }
}

\printf(
    "PHP %s %s, zend.assertions=%s, %d ticks x %d rounds, %d run(s), load %.2f\n",
    \PHP_VERSION,
    \PHP_OS_FAMILY,
    $options['assertions'],
    $ticks,
    $rounds,
    (int) $options['runs'],
    (\sys_getloadavg() ?: [0.0])[0],
);
\printf("%-8s %-9s %6s %12s %10s %10s\n", 'driver', 'setup', 'count', 'ns/tick', 'min', 'max');

foreach ($configurations as [$name, $setup, $count]) {
    $medians = [];
    $error = null;

    for ($run = 0; $run < \max(1, (int) $options['runs']); $run++) {
        $output = \shell_exec(\sprintf(
            '%s -d memory_limit=-1 -d zend.assertions=%s %s --child --driver=%s --setup=%s --count=%d --ticks=%d --rounds=%d 2>&1',
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($options['assertions']),
            \escapeshellarg(__FILE__),
            \escapeshellarg($name),
            $setup,
            $count,
            $ticks,
            $rounds,
        ));
        $result = \json_decode((string) $output, true);

        if (!\is_array($result) || !isset($result['median'])) {
            $error = \trim(\substr((string) $output, 0, 200));

            break;
        }

        $medians[] = $result['median'];
    }

    if ($error !== null) {
        \printf("%-8s %-9s %6d  error: %s\n", $name, $setup, $count, $error);

        continue;
    }

    \sort($medians);
    \printf(
        "%-8s %-9s %6d %12.1f %10.1f %10.1f\n",
        $name,
        $setup,
        $count,
        $medians[\intdiv(\count($medians), 2)],
        $medians[0],
        $medians[\count($medians) - 1],
    );
}
