<?php

declare(strict_types=1);

use Fledge\Loop\Driver\PollDriver;
use Fledge\Loop\Driver\StreamSelectDriver;
use Fledge\Loop\ForkUnwindException;
use Fledge\Loop\LoopStateException;

/*
 * Loop::afterFork(), Loop::fork() and the PollDriver fork guard, with real pcntl_fork() calls. The test runner never forks:
 * every test starts a fresh PHP process, which plays the parent (installs a driver, watches a stream, awaits from
 * {main}, registers a loop signal handler) and forks a child. The child reports through a socket pair, the parent
 * prints one JSON line. A parent that crashes (a shared epoll descriptor can make it segfault) prints nothing.
 */

/** Suspends the calling fiber (or {main}) on the loop for $seconds; the scenarios' stand-in for an async delay(). */
const LOOP_DRIVER_FORK_DELAY = <<<'PHP'
    function loopDelay(float $seconds): void
    {
        $suspension = Fledge\Loop::getSuspension();
        Fledge\Loop::delay($seconds, static fn () => $suspension->resume());
        $suspension->suspend();
    }

    PHP . "\n";

/**
 * @param  array<string, mixed>  $vars  Variables defined (via var_export) before the code runs.
 * @return array<string, mixed>
 */
function loopDriverForkScenario(string $code, array $vars = [], ?string &$stderr = null): array
{
    $prelude = LOOP_DRIVER_FORK_DELAY;

    foreach ($vars as $name => $value) {
        $prelude .= '$'.$name.' = '.var_export($value, true).";\n";
    }

    $result = runPhpSubprocess($prelude.$code);
    ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exitCode] = $result;

    $decoded = json_decode($stdout, true);

    if (! is_array($decoded) || $exitCode !== 0 || $result['timedOut']) {
        $state = $result['timedOut'] ? 'timed out' : "exit {$exitCode}";
        test()->fail("parent process failed ({$state}). stdout: {$stdout} stderr: {$stderr}");
    }

    return $decoded;
}

/**
 * Fails with the child's own report (exception class, message, origin), its exit status and the shared stderr when
 * the child caught an exception, instead of leaving only a missing key in an array diff.
 *
 * @param  array<string, mixed>  $result
 */
function assertForkedChildSucceeded(array $result, string $stderr): void
{
    if (isset($result['child']['error'])) {
        test()->fail(sprintf(
            "forked child failed: %s: %s at %s (child exit %s)\nchild report: %s\nstderr: %s",
            $result['child']['error'],
            $result['child']['message'] ?? '',
            $result['child']['at'] ?? '?',
            var_export($result['childExit'] ?? null, true),
            json_encode($result['child']),
            $stderr,
        ));
    }
}

/*
 * Parent: $parentDriver with a watched socket pair, a loop signal handler and an await from {main}, then fork.
 * Child ($mode): 'afterFork' calls Loop::afterFork($childDriver); 'afterFork+close' also closes the
 * parent's watched stream; 'inherited' keeps using the inherited driver. Then the child does its own socket pair
 * I/O and timers. The parent writes to its stream while the child runs and once more after the child exited.
 */
const LOOP_DRIVER_FORK_IO = <<<'PHP'
    use Fledge\Loop;

    Loop::install($parentDriver);
    Loop::unreference(Loop::onSignal(SIGUSR1, static fn () => null));
    [$watched, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $parentGot = '';
    Loop::unreference(Loop::onReadable($watched, function () use ($watched, &$parentGot) {
        $parentGot .= fread($watched, 100);
    }));
    loopDelay(0.01);
    [$report, $reportPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

    $pid = pcntl_fork();

    if ($pid === 0) {
        $out = [];

        try {
            if ($mode !== 'inherited') {
                $driver = Loop::afterFork($childDriver);
                $out['class'] = $driver::class;
                $out['installed'] = Loop::getDriver() === $driver;
                $out['callbacks'] = count(Loop::getIdentifiers());
                $out['sigusr1Default'] = pcntl_signal_get_handler(SIGUSR1) === SIG_DFL;
                gc_collect_cycles();
                $handle = $driver->getHandle();
                $out['handleUsable'] = true;
                if ($handle instanceof Io\Poll\Context) {
                    try {
                        $handle->wait(Time\Duration::fromSeconds(0));
                    } catch (Throwable) {
                        $out['handleUsable'] = false; // The new driver's descriptor was closed behind its back.
                    }
                }
            }

            if ($mode === 'afterFork+close') {
                fclose($watched);
            }

            [$own, $ownPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $shift = [];
            for ($i = 0; $i < 8; $i++) {
                $shift[] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            }
            fwrite($ownPeer, 'job');
            $childGot = '';
            $ticks = 0;
            $readId = Loop::onReadable($own, function () use ($own, &$childGot) {
                $childGot .= fread($own, 100);
            });
            $timerId = Loop::repeat(0.01, function () use (&$ticks) {
                $ticks++;
            });
            loopDelay(0.2);
            Loop::cancel($readId);
            Loop::cancel($timerId);
            $out['got'] = $childGot;
            $out['ticked'] = $ticks > 0;
        } catch (Throwable $exception) {
            $out['error'] = $exception::class;
            $out['message'] = $exception->getMessage();
            $out['at'] = $exception->getFile().':'.$exception->getLine();
        }

        fwrite($reportPeer, json_encode($out)."\n");
        exit(0);
    }

    loopDelay(0.05);
    fwrite($peer, 'hello');
    loopDelay(0.3);
    pcntl_waitpid($pid, $status);
    fwrite($peer, ' again');
    loopDelay(0.05);

    echo json_encode([
        'parentGot' => $parentGot,
        'childExit' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -pcntl_wtermsig($status),
        'child' => json_decode(fgets($report), true),
    ]);
    PHP;

$requiresFork = ! extension_loaded('pcntl') || ! extension_loaded('posix') || ! function_exists('pcntl_fork');

it('gives a forked child a fresh empty loop while the parent keeps only its own events', function (
    string $parentDriver,
    string $childDriver,
    string $class,
) {
    $result = loopDriverForkScenario(LOOP_DRIVER_FORK_IO, [
        'parentDriver' => $parentDriver,
        'childDriver' => $childDriver,
        'mode' => 'afterFork',
    ], $stderr);

    assertForkedChildSucceeded($result, $stderr);

    expect($result['parentGot'])->toBe('hello again')
        ->and($result['childExit'])->toBe(0)
        ->and($result['child'])->toBe([
            'class' => $class,
            'installed' => true,
            'callbacks' => 0,
            'sigusr1Default' => true,
            'handleUsable' => true,
            'got' => 'job',
            'ticked' => true,
        ]);
})->with([
    'poll parent, poll child' => ['poll', 'poll', PollDriver::class],
    'poll parent, select child' => ['poll', 'select', StreamSelectDriver::class],
    'poll parent, auto child' => ['poll', 'auto', PollDriver::class],
    'select parent, poll child' => ['select', 'poll', PollDriver::class],
    'select parent, select child' => ['select', 'select', StreamSelectDriver::class],
    'select parent, auto child' => ['select', 'auto', PollDriver::class],
])->skip($requiresFork, 'needs pcntl and posix');

it('keeps the parent watching a stream the child closes after afterFork()', function (
    string $parentDriver,
    string $childDriver,
) {
    $result = loopDriverForkScenario(LOOP_DRIVER_FORK_IO, [
        'parentDriver' => $parentDriver,
        'childDriver' => $childDriver,
        'mode' => 'afterFork+close',
    ], $stderr);

    assertForkedChildSucceeded($result, $stderr);

    expect($result['parentGot'])->toBe('hello again')
        ->and($result['child']['got'] ?? null)->toBe('job');
})->with([
    'poll parent, poll child' => ['poll', 'poll'],
    'poll parent, select child' => ['poll', 'select'],
    'select parent, poll child' => ['select', 'poll'],
])->skip($requiresFork, 'needs pcntl and posix');

it('refuses the inherited PollDriver in a child without afterFork() and leaves the parent unaffected', function () {
    $result = loopDriverForkScenario(LOOP_DRIVER_FORK_IO, [
        'parentDriver' => 'poll',
        'childDriver' => null,
        'mode' => 'inherited',
    ]);

    expect($result['parentGot'])->toBe('hello again')
        ->and($result['childExit'])->toBe(0)
        ->and($result['child']['error'] ?? null)->toBe(LoopStateException::class)
        ->and($result['child']['message'])->toContain('used in forked process')
        ->and($result['child']['message'])->toContain('Loop::afterFork()');
})->skip($requiresFork, 'needs pcntl and posix');

it('keeps the parent watching when a child exits without touching the loop', function () {
    $result = loopDriverForkScenario(<<<'PHP'
        use Fledge\Loop;

        Fledge\Loop::install('poll');
        [$watched, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $parentGot = '';
        Loop::unreference(Loop::onReadable($watched, function () use ($watched, &$parentGot) {
            $parentGot .= fread($watched, 100);
        }));
        loopDelay(0.01);

        $pid = pcntl_fork();

        if ($pid === 0) {
            exit(0); // PHP shutdown closes the inherited streams.
        }

        pcntl_waitpid($pid, $status);
        fwrite($peer, 'hello');
        loopDelay(0.1);

        echo json_encode(['parentGot' => $parentGot]);
        PHP);

    expect($result['parentGot'])->toBe('hello');
})->skip($requiresFork, 'needs pcntl and posix');

it('refuses afterFork() inside a fiber and inside an event loop callback', function () {
    $result = loopDriverForkScenario(<<<'PHP'
        use Fledge\Loop;

        $before = Fledge\Loop::install('poll');
        $messages = [];
        $attempt = function (string $where) use (&$messages) {
            try {
                Fledge\Loop::afterFork('select');
                $messages[$where] = null;
            } catch (LogicException $exception) {
                $messages[$where] = $exception->getMessage();
            }
        };

        $suspension = Loop::getSuspension();
        Loop::queue(static function () use ($attempt, $suspension) {
            $attempt('fiber');
            $suspension->resume();
        });
        $suspension->suspend();
        Loop::defer(fn () => $attempt('callback'));
        Loop::run();
        (new Fiber(fn () => $attempt('plain fiber')))->start();

        echo json_encode(['messages' => $messages, 'unchanged' => Loop::getDriver() === $before]);
        PHP);

    expect($result['unchanged'])->toBeTrue()
        ->and(array_keys($result['messages']))->toBe(['fiber', 'callback', 'plain fiber']);

    foreach ($result['messages'] as $message) {
        expect($message)->toContain('must be called from {main}');
    }
});

it('points install() in a forked child to afterFork()', function (string $state, string $reason) {
    $result = loopDriverForkScenario(<<<'PHP'
        use Fledge\Loop;

        Fledge\Loop::install('poll');

        if ($state === 'awaited') {
            loopDelay(0.01);
        } else {
            Loop::onSignal(SIGUSR1, static fn () => null);
        }

        [$report, $reportPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $pid = pcntl_fork();

        if ($pid === 0) {
            try {
                Fledge\Loop::install('poll');
                $message = null;
            } catch (LogicException $exception) {
                $message = $exception->getMessage();
            }

            fwrite($reportPeer, json_encode(['message' => $message])."\n");
            exit(0);
        }

        pcntl_waitpid($pid, $status);
        echo fgets($report);
        PHP, ['state' => $state]);

    expect($result['message'])->toContain($reason)
        ->and($result['message'])->toContain('inherited through fork()')
        ->and($result['message'])->toContain('Loop::afterFork() in the child');
})->with([
    'parent awaited from {main}' => ['awaited', 'while the event loop is running'],
    'parent registered a signal handler' => ['signal', '1 callback(s) registered'],
])->skip($requiresFork, 'needs pcntl and posix');

it('mentions afterFork() when install() refuses in a process that was not forked', function () {
    $result = loopDriverForkScenario(<<<'PHP'
        Fledge\Loop::defer(static fn () => null);

        try {
            Fledge\Loop::install('poll');
            $message = null;
        } catch (LogicException $exception) {
            $message = $exception->getMessage();
        }

        echo json_encode(['message' => $message]);
        PHP);

    expect($result['message'])->toContain('1 callback(s) registered')
        ->and($result['message'])->toContain('In a child forked from a process that used the event loop');
});

it('refuses afterFork() in a process that never forked, keeping its callbacks', function (bool $withCallbacks) {
    $result = loopDriverForkScenario(<<<'PHP'
        use Fledge\Loop;

        $first = Loop::install('poll');
        $fired = [];
        if ($withCallbacks) {
            Loop::delay(0.02, function () use (&$fired) {
                $fired[] = 'timer';
            });
            [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            Loop::onReadable($a, function (string $id) use (&$fired) {
                $fired[] = 'read';
                Loop::cancel($id);
            });
            fwrite($b, 'x');
        }
        $before = count(Loop::getIdentifiers());
        $message = null;
        try {
            Loop::afterFork('select');
        } catch (LogicException $exception) {
            $message = $exception->getMessage();
        }
        $kept = Loop::getDriver() === $first && count(Loop::getIdentifiers()) === $before;
        Loop::run();

        echo json_encode(['message' => $message, 'kept' => $kept, 'fired' => $fired]);
        PHP, ['withCallbacks' => $withCallbacks]);

    expect($result['message'])->toContain('Fledge\\Loop::afterFork() is not in a forked child')
        ->and($result['kept'])->toBeTrue()
        ->and($result['fired'])->toBe($withCallbacks ? ['read', 'timer'] : []);
})->with(['with callbacks' => true, 'without callbacks' => false]);

it('refuses a second afterFork() in the same child and accepts it again in a child of the child', function () {
    $result = loopDriverForkScenario(<<<'PHP'
        use Fledge\Loop;

        Loop::install('poll');
        Loop::unreference(Loop::repeat(10, static fn () => null));
        loopDelay(0.01);
        [$report, $reportPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        $pid = pcntl_fork();

        if ($pid === 0) {
            $child = Loop::afterFork('poll');
            $fired = false;
            Loop::delay(0.02, function () use (&$fired) {
                $fired = true;
            });
            $second = null;
            try {
                Loop::afterFork('poll');
            } catch (LogicException $exception) {
                $second = str_contains($exception->getMessage(), 'not in a forked child');
            }
            $kept = Loop::getDriver() === $child && count(Loop::getIdentifiers()) === 1;
            loopDelay(0.01);

            $grandchildPid = pcntl_fork();
            if ($grandchildPid === 0) {
                $grandchild = Loop::afterFork('select');
                $ran = false;
                Loop::defer(function () use (&$ran) {
                    $ran = true;
                });
                Loop::run();
                fwrite($reportPeer, json_encode([
                    'who' => 'grandchild',
                    'driver' => $grandchild::class,
                    'ran' => $ran,
                    'empty' => Loop::getIdentifiers() === [],
                ])."\n");
                exit(0);
            }

            pcntl_waitpid($grandchildPid, $status);
            Loop::run();
            fwrite($reportPeer, json_encode([
                'who' => 'child',
                'second' => $second,
                'kept' => $kept,
                'fired' => $fired,
                'grandchildExit' => pcntl_wexitstatus($status),
            ])."\n");
            exit(0);
        }

        pcntl_waitpid($pid, $status);
        $lines = [json_decode((string) fgets($report), true), json_decode((string) fgets($report), true)];
        echo json_encode(['lines' => $lines, 'childExit' => pcntl_wexitstatus($status)]);
        PHP);

    expect($result['childExit'])->toBe(0)
        ->and($result['lines'])->toBe([
            ['who' => 'grandchild', 'driver' => StreamSelectDriver::class, 'ran' => true, 'empty' => true],
            ['who' => 'child', 'second' => true, 'kept' => true, 'fired' => true, 'grandchildExit' => 0],
        ]);
})->skip($requiresFork, 'needs pcntl and posix');

it('keeps parent callback ids from naming the child callbacks after afterFork()', function (
    string $parentDriver,
    string $childDriver,
) {
    $result = loopDriverForkScenario(<<<'PHP'
        use Fledge\Loop;

        Loop::install($parentDriver);
        $parentTimer = Loop::repeat(10, static fn () => null); // owned by some parent-built object
        Loop::unreference($parentTimer);
        loopDelay(0.01);
        [$report, $reportPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        $pid = pcntl_fork();

        if ($pid === 0) {
            Loop::afterFork($childDriver);
            $fired = false;
            $childTimer = Loop::delay(0.05, function () use (&$fired) {
                $fired = true;
            });
            Loop::cancel($parentTimer); // what the parent-built object's close() or destructor does
            $enable = null;
            try {
                Loop::enable($parentTimer);
            } catch (Throwable $exception) {
                $enable = $exception::class;
            }
            Loop::run();

            fwrite($reportPeer, json_encode([
                'distinct' => $childTimer !== $parentTimer,
                'fired' => $fired,
                'enable' => $enable,
            ])."\n");
            exit(0);
        }

        pcntl_waitpid($pid, $status);
        echo fgets($report);
        PHP, ['parentDriver' => $parentDriver, 'childDriver' => $childDriver]);

    expect($result)->toBe([
        'distinct' => true,
        'fired' => true,
        'enable' => Fledge\Loop\InvalidCallbackError::class,
    ]);
})->with([
    'poll to poll' => ['poll', 'poll'],
    'poll to select' => ['poll', 'select'],
    'select to poll' => ['select', 'poll'],
    'select to select' => ['select', 'select'],
    'poll to auto' => ['poll', 'auto'],
])->skip($requiresFork, 'needs pcntl and posix');

it('refuses an idle wait on the inherited PollDriver while the parent adds ready watchers', function () {
    // Without the pre-wait check the child segfaulted in about two of three forks on Linux (shared epoll, events
    // whose data lives in the parent's memory), so the scenario runs five rounds.
    $result = loopDriverForkScenario(<<<'PHP'
        use Fledge\Loop;

        Fledge\Loop::install('poll');
        [$watched, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        Loop::unreference(Loop::onReadable($watched, static fn () => null));
        loopDelay(0.01);
        $rounds = [];
        $pairs = [];
        $parentGot = 0;

        for ($round = 0; $round < 5; $round++) {
            [$report, $reportPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $pid = pcntl_fork();

            if ($pid === 0) {
                // No afterFork() and nothing to sync: the delay goes straight to a wait on the inherited Context.
                // The deferred handshake runs after the child's first wait on the inherited Context, just before the loop
                // blocks; with the guard in place that first wait already throws and the handshake never comes.
                Loop::defer(static function () use ($reportPeer): void {
                    fwrite($reportPeer, "waiting\n");
                });

                try {
                    loopDelay(0.3);
                    $out = ['error' => null];
                } catch (Throwable $exception) {
                    $out = ['error' => $exception::class, 'message' => $exception->getMessage()];
                }

                fwrite($reportPeer, json_encode($out)."\n");
                exit(0);
            }

            // The child's handshake, or its report when it threw first (or EOF when it crashed: the parent's copy of
            // the child's end is closed first).
            fclose($reportPeer);
            $first = fgets($report);
            for ($i = 0; $i < 20; $i++) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                $pairs[] = $pair;
                $stream = $pair[0];
                Loop::unreference(Loop::onReadable($stream, function () use ($stream, &$parentGot) {
                    $parentGot += strlen(fread($stream, 10));
                }));
                fwrite($pair[1], 'x');
            }
            loopDelay(0.4);
            pcntl_waitpid($pid, $status);

            $rounds[] = [
                'childExit' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -pcntl_wtermsig($status),
                'child' => json_decode((string) ($first === "waiting\n" ? fgets($report) : $first), true),
            ];
        }

        echo json_encode(['rounds' => $rounds, 'parentGot' => $parentGot]);
        PHP);

    expect($result['parentGot'])->toBe(100);

    foreach ($result['rounds'] as $round) {
        expect($round['childExit'])->toBe(0)
            ->and($round['child']['error'] ?? null)->toBe(LoopStateException::class)
            ->and($round['child']['message'])->toContain('Loop::afterFork()');
    }
})->skip($requiresFork, 'needs pcntl and posix');

it('refuses the inherited PollDriver after a fork from user code that the interest sync runs', function () {
    // A user-space wrapper whose last reference is the driver's: when the sync forgets it, its stream_close() runs and
    // forks. The child is still inside the sync; without a new process check it would go on to wait on the inherited
    // Context (on Linux the parent's epoll descriptor: this sync runs in a non-blocking tick, so a zero-timeout
    // epoll_wait), see the stream written to right before the fork ready, and run the parent's callback. With the
    // check it throws at once, before any wait.
    $result = loopDriverForkScenario(<<<'PHP'
        use Fledge\Loop;

        final class ForkingCloseWrapper
        {
            public $context;
            public static $inner;
            public static ?int $child = null;
            public static int $forkedAt = 0;
            /** @var resource Written right before the fork, so a child that waits even with a zero timeout sees it ready. */
            public static $wake;

            public function stream_open($path, $mode, $options, &$opened): bool { return true; }
            public function stream_read($count) { return ''; }
            public function stream_eof(): bool { return false; }
            public function stream_cast($as) { return self::$inner; }
            public function stream_set_option($option, $arg1, $arg2) { return false; }

            public function stream_close(): void
            {
                fwrite(self::$wake, 'x');
                self::$forkedAt = hrtime(true);
                $pid = pcntl_fork();
                self::$child = $pid === 0 ? 0 : $pid;

                if ($pid > 0) {
                    usleep(200_000); // Gives the child time to reach its wait before the parent reads the data.
                }
            }
        }

        stream_wrapper_register('forkclose', ForkingCloseWrapper::class);
        Fledge\Loop::install('poll');
        [$inner, $innerPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        ForkingCloseWrapper::$inner = $inner;
        [$report, $reportPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        ForkingCloseWrapper::$wake = $b;
        stream_set_blocking($a, false);
        $parentCallbackInChild = false;

        Loop::onReadable($a, static function (string $id, $stream) use (&$parentCallbackInChild): void {
            fread($stream, 100);
            if (ForkingCloseWrapper::$child === 0) {
                $parentCallbackInChild = true;
            }
        });

        $wrapped = fopen('forkclose://x', 'r');
        $wrappedId = Loop::onReadable($wrapped, static fn () => null);
        unset($wrapped);
        loopDelay(0.05);
        Loop::cancel($wrappedId); // The next sync forgets the stream: stream_close() forks there.

        try {
            loopDelay(1.0);
            $error = null;
        } catch (Throwable $exception) {
            $error = ['class' => $exception::class, 'message' => $exception->getMessage()];
        }

        if (ForkingCloseWrapper::$child === 0) {
            fwrite($reportPeer, json_encode([
                'error' => $error,
                'parentCallbackInChild' => $parentCallbackInChild,
                'secondsToError' => (hrtime(true) - ForkingCloseWrapper::$forkedAt) / 1e9,
            ])."\n");
            exit(0);
        }

        pcntl_waitpid(ForkingCloseWrapper::$child, $status);
        echo json_encode([
            'parentError' => $error,
            'childExit' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -pcntl_wtermsig($status),
            'child' => json_decode((string) fgets($report), true),
        ]);
        PHP);

    expect($result['parentError'])->toBeNull()
        ->and($result['childExit'])->toBe(0)
        ->and($result['child']['parentCallbackInChild'])->toBeFalse()
        ->and($result['child']['error']['class'] ?? null)->toBe(LoopStateException::class)
        ->and($result['child']['error']['message'])->toContain('Loop::afterFork()')
        // Thrown before any wait on the inherited Context, so well before the 1 s delay.
        ->and($result['child']['secondsToError'])->toBeLessThan(0.5);
})->skip($requiresFork, 'needs pcntl and posix');

it('collects parent garbage before the child driver opens its descriptor', function (string $childDriver) {
    // An unreachable parent Io\Poll\Context still waiting for the cycle collector at fork time (a replaced
    // PollDriver is after install()). On macOS and BSD its kqueue number is free in the child; collected
    // after the child's new driver took that number, it closed the new driver's kqueue.
    $result = loopDriverForkScenario(<<<'PHP'
        use Fledge\Loop;

        // The parent runs on select (no descriptor of its own), so the garbage Context holds the lowest kqueue
        // number, the one the child's new driver gets. Created after install(), whose setDriver() collects cycles.
        Loop::install('select');
        loopDelay(0.01);

        $garbage = new stdClass();
        $garbage->self = $garbage;
        $garbage->context = new Io\Poll\Context();
        unset($garbage); // Unreachable, but only the cycle collector frees it.
        [$report, $reportPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        $pid = pcntl_fork();

        if ($pid === 0) {
            $out = [];
            try {
                $driver = Loop::afterFork($childDriver);
                gc_collect_cycles();
                $driver->getHandle()?->wait(Time\Duration::fromSeconds(0));
                $out['handleUsable'] = true;
                [$own, $ownPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                fwrite($ownPeer, 'job');
                $got = '';
                $id = Loop::onReadable($own, function () use ($own, &$got) {
                    $got .= fread($own, 100);
                });
                loopDelay(0.05);
                Loop::cancel($id);
                $out['got'] = $got;
            } catch (Throwable $exception) {
                $out['error'] = $exception::class;
                $out['message'] = $exception->getMessage();
                $out['at'] = $exception->getFile().':'.$exception->getLine();
            }

            fwrite($reportPeer, json_encode($out)."\n");
            exit(0);
        }

        pcntl_waitpid($pid, $status);
        echo json_encode([
            'childExit' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -pcntl_wtermsig($status),
            'child' => json_decode((string) fgets($report), true),
        ]);
        PHP, ['childDriver' => $childDriver], $stderr);

    assertForkedChildSucceeded($result, $stderr);

    expect($result['child'])->toBe(['handleUsable' => true, 'got' => 'job']);
})->with(['poll', 'auto'])->skip($requiresFork, 'needs pcntl and posix');

it('throws from afterFork() while the child still references the inherited Io\\Poll\\Context', function () {
    $result = loopDriverForkScenario(<<<'PHP'
        use Fledge\Loop;

        $parentDriver = Loop::install('poll');
        $context = $parentDriver->getHandle(); // kept in a variable the child inherits
        loopDelay(0.01);
        [$report, $reportPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        $pid = pcntl_fork();

        if ($pid === 0) {
            try {
                $driver = Loop::afterFork('poll');
                $out = ['error' => null];
            } catch (LogicException $exception) {
                $out = [
                    'error' => $exception::class,
                    'message' => $exception->getMessage(),
                ];
            }

            $out['installed'] = Fledge\Loop::getDriver() instanceof Fledge\Loop\Driver\PollDriver
                && Fledge\Loop::getDriver() !== $parentDriver;

            fwrite($reportPeer, json_encode($out)."\n");
            exit(0);
        }

        pcntl_waitpid($pid, $status);
        echo json_encode([
            'hasContext' => $context instanceof Io\Poll\Context,
            'backend' => $context->getBackend()->name,
            'child' => json_decode((string) fgets($report), true),
        ]);
        PHP);

    if (! $result['hasContext']) {
        test()->markTestSkipped('the poll driver has no Io\\Poll Context');
    }

    // A kqueue is not inherited by the child, so its Context is parked until exit and a held reference is harmless.
    if ($result['backend'] === 'Kqueue') {
        expect($result['child'])->toBe(['error' => null, 'installed' => true]);

        return;
    }

    expect($result['child']['error'])->toBe(LoopStateException::class)
        ->and($result['child']['message'])->toContain('inherited Io\\Poll\\Context is still referenced')
        ->and($result['child']['installed'])->toBeTrue();
})->skip($requiresFork, 'needs pcntl and posix');

it('frees a replaced driver with its descriptor', function () {
    $result = loopDriverForkScenario(<<<'PHP'
        use Fledge\Loop;

        function openDescriptors(): array
        {
            $open = [];
            for ($fd = 0; $fd < 256; $fd++) {
                $probe = @fopen("php://fd/{$fd}", 'r');
                if ($probe !== false) {
                    $open[] = $fd;
                    fclose($probe);
                }
            }

            return $open;
        }

        [$watched, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $base = openDescriptors();
        Loop::install('select');
        $afterSelect = array_values(array_diff(openDescriptors(), $base));

        $replaced = [];
        $opened = [];
        foreach (['poll', 'select', 'poll'] as $name) {
            $driver = Loop::install($name);
            $replaced[] = WeakReference::create($driver);
            unset($driver);
            // A stream watcher opens the poll driver's descriptor (lazily, at the first one).
            $id = Fledge\Loop::onReadable($watched, static fn () => null);
            Fledge\Loop::defer(static function () use ($id): void {
                Fledge\Loop::defer(static fn () => Fledge\Loop::cancel($id));
            });
            Fledge\Loop::run(); // a driver whose loop ran is what the loop keeps alive
            $opened[] = count(array_diff(openDescriptors(), $base));
        }
        Loop::install('select');

        echo json_encode([
            'afterSelect' => $afterSelect,
            'leftOpen' => array_values(array_diff(openDescriptors(), $base)),
            'alive' => array_map(static fn (WeakReference $reference) => $reference->get() !== null, $replaced),
            'opened' => $opened,
        ]);
        PHP);

    expect($result)->toBe([
        'afterSelect' => [],
        'leftOpen' => [],
        'alive' => [false, false, false],
        'opened' => [1, 0, 1],
    ]);
});

it('forks with Loop::fork(): pid in the parent, a fresh empty loop in the child', function (string $parentDriver) {
    $result = loopDriverForkScenario(<<<'PHP'
        use Fledge\Loop;

        Loop::install($parentDriver);
        Loop::unreference(Loop::onSignal(SIGUSR1, static fn () => null));
        [$watched, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $parentGot = '';
        Loop::unreference(Loop::onReadable($watched, function () use ($watched, &$parentGot) {
            $parentGot .= fread($watched, 100);
        }));
        $parentTimer = Loop::repeat(10, static fn () => null);
        Loop::unreference($parentTimer);
        loopDelay(0.01);
        [$report, $reportPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        $pid = Loop::fork();

        if ($pid === 0) {
            $out = [];
            try {
                $out['class'] = Loop::getDriver()::class;
                $out['callbacks'] = count(Loop::getIdentifiers());
                $out['sigusr1Default'] = pcntl_signal_get_handler(SIGUSR1) === SIG_DFL;
                [$own, $ownPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                fwrite($ownPeer, 'job');
                $got = '';
                $id = Loop::onReadable($own, function () use ($own, &$got) {
                    $got .= fread($own, 100);
                });
                $out['freshId'] = $id !== $parentTimer;
                loopDelay(0.1);
                Loop::cancel($id);
                $out['got'] = $got;
            } catch (Throwable $exception) {
                $out['error'] = $exception::class;
                $out['message'] = $exception->getMessage();
                $out['at'] = $exception->getFile().':'.$exception->getLine();
            }

            fwrite($reportPeer, json_encode($out)."\n");
            exit(0);
        }

        loopDelay(0.05);
        fwrite($peer, 'hello');
        loopDelay(0.2);
        pcntl_waitpid($pid, $status);
        fwrite($peer, ' again');
        loopDelay(0.05);

        echo json_encode([
            'pidPositive' => $pid > 0,
            'parentGot' => $parentGot,
            'childExit' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -pcntl_wtermsig($status),
            'child' => json_decode((string) fgets($report), true),
        ]);
        PHP, ['parentDriver' => $parentDriver], $stderr);

    assertForkedChildSucceeded($result, $stderr);

    expect($result)->toBe([
        'pidPositive' => true,
        'parentGot' => 'hello again',
        'childExit' => 0,
        'child' => [
            'class' => PollDriver::isSupported() ? PollDriver::class : StreamSelectDriver::class,
            'callbacks' => 0,
            'sigusr1Default' => true,
            'freshId' => true,
            'got' => 'job',
        ],
    ]);
})->with(['poll', 'select'])->skip($requiresFork, 'needs pcntl and posix');

it('refuses Loop::fork() inside a fiber and inside an event loop callback, without forking', function () {
    $result = loopDriverForkScenario(<<<'PHP'
        use Fledge\Loop;

        $messages = [];
        $attempt = function (string $where) use (&$messages) {
            try {
                $pid = Loop::fork();
                if ($pid === 0) {
                    exit(3); // must never happen
                }
                $messages[$where] = 'forked';
            } catch (LogicException $exception) {
                $messages[$where] = $exception->getMessage();
            }
        };

        (new Fiber(fn () => $attempt('fiber')))->start();
        Loop::defer(fn () => $attempt('callback'));
        Loop::run();

        echo json_encode(['messages' => $messages]);
        PHP);

    expect(array_keys($result['messages']))->toBe(['fiber', 'callback']);

    foreach ($result['messages'] as $message) {
        expect($message)->toContain('Fledge\\Loop::fork() must be called from {main}');
    }
})->skip($requiresFork, 'needs pcntl and posix');

it('has nothing to abandon in a child of a parent that never touched a stream', function (string $mode) {
    $result = loopDriverForkScenario(<<<'PHP'
        use Fledge\Loop;

        $parent = Loop::install('poll');
        Loop::unreference(Loop::onSignal(SIGUSR1, static fn () => null));
        Loop::unreference(Loop::repeat(10, static fn () => null));
        loopDelay(0.01); // timers, a signal and an await: no stream, so no descriptor yet
        [$report, $reportPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        $pid = pcntl_fork();

        if ($pid === 0) {
            $out = ['inheritedHandle' => $parent->getHandle()];
            try {
                if ($mode === 'afterFork') {
                    Loop::afterFork('poll');
                } else {
                    loopDelay(0.01); // timers alone need no kernel object: the inherited driver still works
                    $out['timersWithoutAfterFork'] = true;
                }

                [$own, $ownPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                fwrite($ownPeer, 'job');
                $got = '';
                $id = Loop::onReadable($own, function () use ($own, &$got) {
                    $got .= fread($own, 100);
                });
                loopDelay(0.05);
                Loop::cancel($id);
                $out['got'] = $got;
            } catch (Throwable $exception) {
                $out['error'] = $exception::class;
                $out['message'] = $exception->getMessage();
            }

            fwrite($reportPeer, json_encode($out)."\n");
            exit(0);
        }

        pcntl_waitpid($pid, $status);

        // The parent opens its descriptor only now, after the fork, and uses it normally.
        [$watched, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $parentGot = '';
        $id = Loop::onReadable($watched, function () use ($watched, &$parentGot) {
            $parentGot .= fread($watched, 100);
        });
        fwrite($peer, 'hello');
        loopDelay(0.05);
        Loop::cancel($id);

        echo json_encode([
            'parentGot' => $parentGot,
            'childExit' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -pcntl_wtermsig($status),
            'child' => json_decode((string) fgets($report), true),
        ]);
        PHP, ['mode' => $mode]);

    expect($result['parentGot'])->toBe('hello')
        ->and($result['childExit'])->toBe(0)
        ->and($result['child']['inheritedHandle'])->toBeNull();

    if ($mode === 'afterFork') {
        expect($result['child'])->toBe(['inheritedHandle' => null, 'got' => 'job']);

        return;
    }

    // Without afterFork(), the first stream watcher needs a kernel object the inherited driver must not create.
    expect($result['child']['timersWithoutAfterFork'] ?? null)->toBeTrue()
        ->and($result['child']['error'] ?? null)->toBe(LoopStateException::class)
        ->and($result['child']['message'])->toContain('used in forked process')
        ->and($result['child']['message'])->toContain('Fledge\\Loop::afterFork()');
})->with(['afterFork', 'inherited'])->skip($requiresFork, 'needs pcntl and posix');

/*
 * Fibers suspended in the parent unwind in the child during afterFork(). Their finally blocks may throw, or try to
 * suspend (a FiberError in an unwinding fiber); neither may keep afterFork() from installing the requested driver with
 * the continued id sequence, nor stop the other fibers from unwinding there. The finally blocks only misbehave in the
 * child, so the parent exits cleanly.
 */
const LOOP_FORK_UNWIND = <<<'PHP'
    use Fledge\Loop;

    $parentPid = getmypid();
    $unwound = [];
    Loop::install('poll');

    foreach ($kinds as $i => $kind) {
        $fiber = new Fiber(function () use ($i, $kind, $parentPid, &$unwound) {
            $suspension = Loop::getSuspension();
            Loop::unreference(Loop::delay(100, fn () => $suspension->resume()));
            try {
                $suspension->suspend();
            } finally {
                if (getmypid() !== $parentPid) {
                    $unwound[] = $i;
                    if ($kind === 'throw') {
                        throw new RuntimeException("cleanup $i failed");
                    }
                    if ($kind === 'suspend') {
                        $again = Loop::getSuspension();
                        Loop::delay(0.01, fn () => $again->resume());
                        $again->suspend();
                    }
                }
            }
        });
        $fiber->start();
    }
    unset($fiber);
    loopDelay(0.01);
    $parentIds = Loop::getIdentifiers();
    [$report, $reportPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

    $pid = $useFork ? Loop::fork() : pcntl_fork();

    if ($pid === 0) {
        $error = null;
        if (! $useFork) {
            try {
                Loop::afterFork('select');
            } catch (Throwable $exception) {
                $error = [
                    $exception::class,
                    $exception->getPrevious()?->getMessage(),
                    $exception instanceof Fledge\Loop\ForkUnwindException && $exception->getUnwindErrors()[0] === $exception->getPrevious(),
                    $exception instanceof RuntimeException,
                ];
            }
        }
        $id = Loop::defer(static fn () => null);
        Loop::run();
        sort($unwound);
        fwrite($reportPeer, json_encode([
            'error' => $error,
            'driver' => Loop::getDriver()::class,
            'aliases' => in_array($id, $parentIds, true),
            'unwound' => $unwound,
        ])."\n");
        exit(0);
    }

    pcntl_waitpid($pid, $status);
    echo json_encode(['child' => json_decode((string) fgets($report), true), 'childExit' => pcntl_wexitstatus($status)]);
    PHP;

it('installs the requested driver in the child even when unwinding parent fibers throw', function (array $kinds, ?string $firstError) {
    $result = loopDriverForkScenario(LOOP_FORK_UNWIND, ['kinds' => $kinds, 'useFork' => false], $stderr);

    expect($result['childExit'])->toBe(0, $stderr)
        ->and($result['child']['driver'])->toBe(StreamSelectDriver::class)
        ->and($result['child']['aliases'])->toBeFalse()
        ->and($result['child']['unwound'])->toBe(array_keys($kinds))
        ->and($result['child']['error'])->toBe($firstError === null ? null : [
            ForkUnwindException::class,
            $firstError,
            // The first unwinding error leads getUnwindErrors(); still a RuntimeException for older catches.
            true,
            true,
        ]);
})->with([
    'three fibers whose finally throws' => [['throw', 'throw', 'throw'], 'cleanup 0 failed'],
    'three fibers whose finally suspends' => [['suspend', 'suspend', 'suspend'], 'Cannot suspend in a force-closed fiber'],
    'two fibers, the first one throws' => [['throw', 'clean'], 'cleanup 0 failed'],
    'two fibers, both clean' => [['clean', 'clean'], null],
])->skip($requiresFork, 'needs pcntl and posix');

it('lets a Loop::fork() child carry on when unwinding parent fibers throw, logging their errors', function () {
    $result = loopDriverForkScenario(LOOP_FORK_UNWIND, ['kinds' => ['throw', 'clean'], 'useFork' => true], $stderr);

    expect($result['childExit'])->toBe(0, $stderr)
        ->and($result['child']['driver'])->toBe(PollDriver::class)
        ->and($result['child']['aliases'])->toBeFalse()
        ->and($result['child']['unwound'])->toBe([0, 1])
        ->and($stderr)->toContain('a fiber inherited from the parent threw while unwinding in the child')
        ->and($stderr)->toContain('cleanup 0 failed');
})->skip($requiresFork, 'needs pcntl and posix');
