<?php

declare(strict_types=1);

/*
 * A signal that arrives after a tick's pcntl_signal_dispatch() and before the kernel wait starts does not interrupt
 * that wait. With an idle socket watched and no timer, the wait has no timeout of its own, so without a cap such a
 * signal (a SIGTERM for a graceful shutdown) would wait for the next I/O event. Both drivers cap every wait at
 * IdleSleep::SIGNAL_WAIT_CAP while signal callbacks are enabled.
 *
 * The child process runs 1000 rounds. In each, a helper process sends SIGTERM after a random sub-millisecond delay
 * from the moment the loop has completed a tick with the signal callback active, which lands the signal around the
 * dispatch-then-wait window often enough to hit it. Every round must see the signal callback within the cap plus a
 * margin; an unreferenced 5 s timer ends a round that would otherwise hang, and the whole child is killed after 120 s.
 */

const SIGNAL_WAIT_RACE = <<<'PHP'
    use Fledge\Loop\Internal\IdleSleep;

    $driver = $name === 'select' ? new Fledge\Loop\Driver\StreamSelectDriver() : new Fledge\Loop\Driver\PollDriver();
    [$idle, $idlePeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    [$go, $goPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $main = getmypid();
    stream_set_timeout($goPeer, 30);

    $helper = pcntl_fork();
    if ($helper === 0) {
        // Waits for "go", sleeps 0 to 1000 microseconds, sends SIGTERM; "q" or EOF (the main process is gone) ends it.
        fclose($go);
        while (($byte = fread($goPeer, 1)) === 'g') {
            usleep(random_int(0, 1000));
            posix_kill($main, SIGTERM);
        }
        exit(0);
    }

    $driver->onReadable($idle, static fn () => null);
    $latencies = [];
    $hung = 0;

    for ($round = 0; $round < $rounds; $round++) {
        $handled = null;
        $signalId = $driver->onSignal(SIGTERM, function () use ($driver, &$handled) {
            $handled = hrtime(true);
            $driver->stop();
        });
        $guard = $driver->delay(5, function () use ($driver) {
            $driver->stop();
        });
        $driver->unreference($guard);

        // Readiness handshake. onSignal() only queues the callback: the driver installs its pcntl_signal() handler in
        // activate(), at the start of the next tick (AbstractDriver::tick()), and cancel() below restores SIG_DFL. A
        // SIGTERM sent before that tick would kill this process by the default disposition. So "go" is sent from a
        // deferred callback queued by a deferred callback: defer() always lands in the next tick, so the inner one
        // runs after a full tick with the handler installed, and the random delay starts from there.
        $start = null;
        $driver->defer(static function () use ($driver, $go, &$start): void {
            $driver->defer(static function () use ($go, &$start): void {
                $start = hrtime(true);
                fwrite($go, 'g');
            });
        });
        $driver->run();
        $driver->cancel($signalId);
        $driver->cancel($guard);

        if ($handled === null || $start === null) {
            $hung++;
        } else {
            $latencies[] = ($handled - $start) / 1e9;
        }
    }

    fwrite($go, 'q');
    pcntl_waitpid($helper, $status);
    sort($latencies);

    echo json_encode([
        'cap' => IdleSleep::SIGNAL_WAIT_CAP,
        'hung' => $hung,
        'handled' => count($latencies),
        'max' => $latencies === [] ? null : end($latencies),
        'over' => count(array_filter($latencies, static fn (float $l) => $l > 0.05)),
    ]);
    PHP;

it('dispatches a signal racing the kernel wait within the cap', function (string $name) {
    $rounds = 1000;
    $result = runPhpSubprocess(
        '$name = '.var_export($name, true).'; $rounds = '.$rounds.";\n".SIGNAL_WAIT_RACE,
        timeout: 120.0,
    );

    expect($result['timedOut'])->toBeFalse("stderr: {$result['stderr']}")
        // proc_close() returns the signal number for a child killed by a signal (15: SIGTERM, default disposition).
        ->and($result['exit'])->toBe(0, "exit status or signal {$result['exit']}; stdout: {$result['stdout']} stderr: {$result['stderr']}");

    $report = json_decode($result['stdout'], true);

    expect($report['hung'])->toBe(0)
        ->and($report['handled'])->toBe($rounds)
        ->and($report['max'])->toBeLessThan($report['cap'] + 0.5);
})->with(['select', 'poll'])->skip(! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'needs pcntl and posix');
