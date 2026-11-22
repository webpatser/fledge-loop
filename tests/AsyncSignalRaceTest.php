<?php

declare(strict_types=1);

/*
 * A process with async signal dispatch (pcntl_async_signals(true)) and a plain pcntl_signal(SIGTERM) handler that
 * defers stop() onto the loop, with no loop signal callback: the shutdown pattern of a long-running server. When the
 * signal lands between the loop's last mayBlock() check and the kernel wait, the handler runs and queues the stop,
 * and the wait blocks anyway: no timer is due and the watched streams stay quiet. Both drivers cap every wait at
 * IdleSleep::SIGNAL_WAIT_CAP while async dispatch is on, so such a round ends within the cap.
 *
 * The child process runs 300 rounds. In each, a helper process writes one byte to a watched socket and sends SIGTERM
 * 0 to 400 microseconds later, which lands the signal around the moment the loop goes back to its wait after reading
 * that byte. An unreferenced 5 s timer ends a round that would otherwise hang; the whole child is killed after 120 s.
 */

const ASYNC_SIGNAL_RACE = <<<'PHP'
    use Fledge\Loop;
    use Fledge\Loop\Internal\IdleSleep;

    [$data, $dataPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    [$go, $goPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $main = getmypid();

    // The helper is forked before the loop exists, so it inherits no driver.
    $helper = pcntl_fork();
    if ($helper === 0) {
        fclose($go);
        fclose($data);
        // "g": let the loop settle into its wait, write a byte, spin 0 to 400 us, send SIGTERM. "q" or EOF ends it.
        while (fread($goPeer, 1) === 'g') {
            usleep(random_int(1000, 3000));
            fwrite($dataPeer, 'x');
            $spin = random_int(0, 400) * 1000;
            $from = hrtime(true);
            while (hrtime(true) - $from < $spin) {
            }
            posix_kill($main, SIGTERM);
        }
        exit(0);
    }

    fclose($goPeer);
    fclose($dataPeer);

    Loop::setDriver($name === 'select' ? new Fledge\Loop\Driver\StreamSelectDriver() : new Fledge\Loop\Driver\PollDriver());

    $signalledAt = null;
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$signalledAt): void {
        $signalledAt = hrtime(true);
        Loop::defer(static fn () => Loop::getDriver()->stop());
    });

    stream_set_blocking($data, false);
    Loop::onReadable($data, static function () use ($data): void {
        fread($data, 64);
    });

    $latencies = [];
    $unhandled = 0;

    for ($round = 0; $round < $rounds; $round++) {
        $signalledAt = null;
        $guard = Loop::unreference(Loop::delay(5, static fn () => Loop::getDriver()->stop()));
        Loop::defer(static function () use ($go): void {
            fwrite($go, 'g');
        });
        Loop::run();
        $returnedAt = hrtime(true);
        Loop::cancel($guard);

        if ($signalledAt === null) {
            $unhandled++;
        } else {
            $latencies[] = ($returnedAt - $signalledAt) / 1e9;
        }
    }

    fwrite($go, 'q');
    pcntl_waitpid($helper, $status);
    sort($latencies);
    $count = count($latencies);

    echo json_encode([
        'cap' => IdleSleep::SIGNAL_WAIT_CAP,
        'unhandled' => $unhandled,
        'handled' => $count,
        'p50' => $count === 0 ? null : $latencies[intdiv($count, 2)],
        'p99' => $count === 0 ? null : $latencies[min($count - 1, (int) floor($count * 0.99))],
        'max' => $count === 0 ? null : end($latencies),
        'over100ms' => count(array_filter($latencies, static fn (float $l) => $l > 0.1)),
        'over3s' => count(array_filter($latencies, static fn (float $l) => $l > 3.0)),
    ]);
    PHP;

it('ends every wait within the cap when an async signal handler defers stop()', function (string $name, string $assertions) {
    $rounds = 300;
    $result = runPhpSubprocess(
        '$name = '.var_export($name, true).'; $rounds = '.$rounds.";\n".ASYNC_SIGNAL_RACE,
        timeout: 120.0,
        ini: ['zend.assertions' => $assertions],
    );

    expect($result['timedOut'])->toBeFalse("stderr: {$result['stderr']}")
        ->and($result['exit'])->toBe(0, "exit status or signal {$result['exit']}; stdout: {$result['stdout']} stderr: {$result['stderr']}");

    $report = json_decode($result['stdout'], true);

    expect($report)->toBeArray("stdout: {$result['stdout']} stderr: {$result['stderr']}")
        ->and($report['unhandled'])->toBe(0)
        ->and($report['handled'])->toBe($rounds)
        ->and($report['over3s'])->toBe(0, json_encode($report))
        ->and($report['max'])->toBeLessThan($report['cap'] + 0.5, json_encode($report));
})->with(['select', 'poll'])->with(['debug' => '1', 'production' => '-1'])
    ->skip(! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'needs pcntl and posix');
