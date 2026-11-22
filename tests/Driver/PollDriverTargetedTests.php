<?php

declare(strict_types=1);

namespace Fledge\Loop\Tests\Driver;

use Fledge\Loop\Driver;
use Fledge\Loop\Driver\PollDriver;
use Fledge\Loop\Tests\Fixtures\CastingStreamWrapper;

/**
 * Targeted tests for PollDriver behaviour that the generic driver suite does not cover. Mixed into the
 * abstract per-backend test case, so every available backend runs all of them. Each test is bounded by a watchdog
 * timer so a driver bug fails the test instead of hanging the suite.
 *
 * @property Driver $loop
 */
trait PollDriverTargetedTests
{
    /**
     * Run the loop after $setup registered its callbacks; fail if it does not finish within $limit seconds.
     * The watchdog is unreferenced, so it never keeps an otherwise empty loop alive.
     *
     * @param callable(Driver): void $setup
     */
    private function runBounded(callable $setup, float $limit = 5.0): void
    {
        $timedOut = false;
        $loop = $this->loop;

        $watchdog = $loop->delay($limit, static function () use (&$timedOut, $loop): void {
            $timedOut = true;
            $loop->stop();
        });
        $loop->unreference($watchdog);

        $setup($loop);
        $loop->run();
        $loop->cancel($watchdog);

        self::assertFalse($timedOut, 'Watchdog fired: the loop did not finish within ' . $limit . ' seconds');
    }

    /**
     * @return array{resource, resource}
     */
    private function socketPair(): array
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair, 'stream_socket_pair() failed');

        \stream_set_blocking($pair[0], false);
        \stream_set_blocking($pair[1], false);

        return $pair;
    }

    public function testReadAndWriteCallbacksOnSameStreamBothFire(): void
    {
        [$a, $b] = $this->socketPair();
        \fwrite($b, 'ping');

        $readFired = 0;
        $writeFired = 0;
        $received = '';

        $this->runBounded(function (Driver $loop) use ($a, &$readFired, &$writeFired, &$received): void {
            $loop->onReadable($a, function (string $id, $stream) use ($loop, &$readFired, &$received): void {
                $readFired++;
                $received .= \fread($stream, 8192);
                $loop->cancel($id);
            });

            $loop->onWritable($a, function (string $id) use ($loop, &$writeFired): void {
                $writeFired++;
                $loop->cancel($id);
            });
        });

        self::assertSame(1, $readFired);
        self::assertSame(1, $writeFired);
        self::assertSame('ping', $received);
    }

    public function testCancellingOneOfTwoReadableCallbacksKeepsTheOtherActive(): void
    {
        [$a, $b] = $this->socketPair();

        $first = 0;
        $second = 0;

        $this->runBounded(function (Driver $loop) use ($a, $b, &$first, &$second): void {
            $firstId = $loop->onReadable($a, function () use (&$first): void {
                $first++;
            });

            $loop->onReadable($a, function (string $id, $stream) use ($loop, &$second): void {
                $second++;
                \fread($stream, 8192);
                $loop->cancel($id);
            });

            $loop->cancel($firstId);
            \fwrite($b, 'x');
        });

        self::assertSame(0, $first, 'The cancelled callback must not fire');
        self::assertSame(1, $second, 'The remaining callback must still fire');
    }

    public function testCancellingAllReadableCallbacksStopsWatchingTheStream(): void
    {
        [$a, $b] = $this->socketPair();

        $fired = 0;
        $later = false;

        $this->runBounded(function (Driver $loop) use ($a, $b, &$fired, &$later): void {
            $ids = [
                $loop->onReadable($a, function () use (&$fired): void {
                    $fired++;
                }),
                $loop->onReadable($a, function () use (&$fired): void {
                    $fired++;
                }),
            ];

            foreach ($ids as $id) {
                $loop->cancel($id);
            }

            \fwrite($b, 'x');

            // Data stays unread, so a stale registration would fire repeatedly while this timer is pending.
            $loop->delay(0.1, function () use (&$later): void {
                $later = true;
            });
        });

        self::assertTrue($later);
        self::assertSame(0, $fired);

        // The stream can be registered again after every callback was cancelled.
        $again = 0;
        $this->runBounded(function (Driver $loop) use ($a, &$again): void {
            $loop->onReadable($a, function (string $id, $stream) use ($loop, &$again): void {
                $again++;
                \fread($stream, 8192);
                $loop->cancel($id);
            });
        });

        self::assertSame(1, $again);
    }

    public function testDisableThenEnableCallback(): void
    {
        [$a, $b] = $this->socketPair();
        \fwrite($b, 'x');

        $fired = 0;
        $firedWhileDisabled = null;

        $this->runBounded(function (Driver $loop) use ($a, &$fired, &$firedWhileDisabled): void {
            $id = $loop->onReadable($a, function (string $id, $stream) use ($loop, &$fired): void {
                $fired++;
                \fread($stream, 8192);
                $loop->cancel($id);
            });

            $loop->disable($id);

            $loop->delay(0.1, function () use ($loop, $id, &$fired, &$firedWhileDisabled): void {
                $firedWhileDisabled = $fired;
                $loop->enable($id);
            });
        });

        self::assertSame(0, $firedWhileDisabled, 'A disabled callback must not fire');
        self::assertSame(1, $fired, 'A re-enabled callback must fire');
    }

    public function testDisablingOneEventKeepsTheOtherEventOnTheSameStream(): void
    {
        [$a, $b] = $this->socketPair();
        \fwrite($b, 'x');

        $readFired = 0;
        $writeFired = 0;

        $this->runBounded(function (Driver $loop) use ($a, &$readFired, &$writeFired): void {
            $readId = $loop->onReadable($a, function () use (&$readFired): void {
                $readFired++;
            });

            $loop->onWritable($a, function (string $id) use ($loop, $readId, &$writeFired): void {
                $writeFired++;
                $loop->cancel($id);
                $loop->cancel($readId);
            });

            $loop->disable($readId);
        });

        self::assertSame(0, $readFired);
        self::assertSame(1, $writeFired);
    }

    /**
     * Cancel-before-close contract, debug mode (assertions are active in this suite): closing a stream while one of
     * its callbacks is enabled throws out of run(), naming the callback and where its closure is defined.
     */
    public function testStreamClosedWhileWatchedThrowsInDebugMode(): void
    {
        if (\ini_get('zend.assertions') !== '1' && !\filter_var(\getenv(PollDriver::DEBUG_ENV), \FILTER_VALIDATE_BOOL)) {
            self::markTestSkipped('Debug mode is off (assertions inactive, FLEDGE_DEBUG unset): the safety-net tests cover production');
        }

        [$a] = $this->socketPair();
        $closedId = null;

        try {
            $this->runBounded(function (Driver $loop) use ($a, &$closedId): void {
                $closedId = $loop->onReadable($a, static function (): void {
                });

                $loop->defer(static function () use ($a): void {
                    \fclose($a);
                });
            });

            self::fail('Closing a watched stream must throw out of run() in debug mode');
        } catch (\Error $error) {
            self::assertStringContainsString(\sprintf('"%s" (readable, ', $closedId), $error->getMessage());
            self::assertStringContainsString(__FILE__, $error->getMessage());
            self::assertStringContainsString("Cancel a stream's callbacks before closing the stream", $error->getMessage());
        } finally {
            if ($closedId !== null) {
                $this->loop->cancel($closedId);
            }
        }
    }

    /**
     * FLEDGE_DEBUG turns the contract check on without assertions.
     */
    public function testFledgeDebugEnablesTheClosedStreamCheckWithoutAssertions(): void
    {
        $result = \runPhpSubprocess(
            $this->closedWhileWatchedScenario(0),
            env: [PollDriver::DEBUG_ENV => '1'],
            timeout: 20.0,
            ini: ['zend.assertions' => '0'],
        );

        self::assertFalse($result['timedOut'], 'The scenario hung');
        self::assertStringContainsString("Cancel a stream's callbacks before closing the stream", $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('"fired"', $result['stdout']);
    }

    /**
     * Production safety net (assertions off, FLEDGE_DEBUG unset): there is no per-tick scan, so a stream closed while
     * watched is found by the bounded round-robin sweep, at most SWEEP_BUDGET watchers once per SWEEP_INTERVAL. Here
     * the closed stream sits in the second slice of a round (SWEEP_BUDGET + 100 idle watchers are registered before
     * it), so it is found by the second sweep: between 1 and 3 seconds after the close. Meanwhile other streams keep
     * working, and once found its callback runs as if the stream were ready instead of the loop hanging.
     */
    public function testSafetyNetFindsStreamClosedWhileWatchedWithoutAssertions(): void
    {
        $budget = (new \ReflectionClassConstant(PollDriver::class, 'SWEEP_BUDGET'))->getValue();
        $interval = (new \ReflectionClassConstant(PollDriver::class, 'SWEEP_INTERVAL'))->getValue();
        \assert(\is_int($budget) && \is_float($interval));

        $result = \runPhpSubprocess(
            $this->closedWhileWatchedScenario($budget + 100),
            env: [PollDriver::DEBUG_ENV => '0'],
            timeout: 30.0,
            ini: ['zend.assertions' => '0'],
        );

        self::assertFalse($result['timedOut'], 'The loop hung on a stream closed while watched');

        if (\str_contains($result['stdout'], '"skip"')) {
            self::markTestSkipped(\trim($result['stdout']));
        }

        $outcome = \json_decode(\trim($result['stdout']), true);
        self::assertIsArray($outcome, 'Scenario output: ' . $result['stdout'] . $result['stderr']);
        self::assertFalse($outcome['assertions'], 'The scenario must run with assertions off');
        self::assertTrue($outcome['otherFiredFirst'], 'Another stream must keep working before the sweep finds the closed one');
        self::assertFalse($outcome['streamValid'], 'The callback sees the closed (invalid) stream');
        self::assertGreaterThanOrEqual(0.9 * $interval, $outcome['latency'], 'Found too early: is there still a per-tick scan?');
        self::assertLessThan(3 * $interval + 0.5, $outcome['latency'], 'Not found within two sweeps');
    }

    /**
     * PHP code for a child process: $idle idle socket pairs are watched first, then stream $a, which a deferred
     * callback closes without cancelling its readable callback, while stream $c gets data. Prints JSON with the delay
     * between the close and $a's callback.
     */
    private function closedWhileWatchedScenario(int $idle): string
    {
        $backend = $this->backend()->name;

        return <<<PHP
            \$idle = {$idle};
            \$needed = 2 * \$idle + 64;
            \$limits = posix_getrlimit(POSIX_RLIMIT_NOFILE);
            if (\$limits[0] !== 'unlimited' && (int) \$limits[0] < \$needed) {
                \$hard = \$limits[1] === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int) \$limits[1];
                if ((\$limits[1] !== 'unlimited' && (int) \$limits[1] < \$needed) || !@posix_setrlimit(POSIX_RLIMIT_NOFILE, \$needed, \$hard)) {
                    echo json_encode(['skip' => "cannot raise the open files limit to \$needed"]), "\\n";
                    exit(0);
                }
            }

            \$loop = new Fledge\\Loop\\Driver\\PollDriver(Io\\Poll\\Backend::{$backend});
            \$pairs = [];
            for (\$i = 0; \$i < \$idle; \$i++) {
                \$pairs[] = \$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                \$loop->onReadable(\$pair[0], static fn () => throw new LogicException('An idle stream fired'));
            }

            [\$a, \$b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            [\$c, \$d] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            \$closedAt = null;
            \$otherFiredAt = null;

            \$loop->onReadable(\$a, static function (string \$id, \$stream) use (\$loop, &\$closedAt, &\$otherFiredAt): void {
                \$now = hrtime(true);
                \$assertions = false;
                assert(\$assertions = true);
                echo json_encode([
                    'fired' => true,
                    'assertions' => \$assertions,
                    'latency' => (\$now - \$closedAt) / 1e9,
                    'otherFiredFirst' => \$otherFiredAt !== null && \$otherFiredAt < \$now,
                    'streamValid' => is_resource(\$stream),
                ]), "\\n";
                \$loop->cancel(\$id);
                \$loop->stop();
            });

            \$loop->onReadable(\$c, static function (string \$id, \$stream) use (\$loop, &\$otherFiredAt): void {
                fread(\$stream, 8);
                \$otherFiredAt = hrtime(true);
                \$loop->cancel(\$id);
            });

            \$loop->defer(static function () use (\$a, \$d, &\$closedAt): void {
                fclose(\$a);
                \$closedAt = hrtime(true);
                fwrite(\$d, 'x');
            });

            \$loop->run();
            PHP;
    }

    public function testFileDescriptorsAboveSelectLimitAreWatched(): void
    {
        if (!\function_exists('posix_getrlimit') || !\function_exists('posix_setrlimit')) {
            self::markTestSkipped('ext-posix is required to inspect and raise the open files limit');
        }

        $pairCount = 530; // 1060 descriptors, so the last pair is certainly numbered above 1024
        $needed = $pairCount * 2 + 128;

        $limits = \posix_getrlimit();
        $soft = $limits['soft openfiles'] ?? 'unlimited';
        $hard = $limits['hard openfiles'] ?? 'unlimited';
        $restore = null;

        if ($soft !== 'unlimited' && $soft < $needed) {
            if ($hard !== 'unlimited' && $hard < $needed) {
                self::markTestSkipped("Hard open files limit is $hard, need $needed to open descriptors above 1024");
            }

            $hardValue = $hard === 'unlimited' ? \POSIX_RLIMIT_INFINITY : $hard;
            if (!@\posix_setrlimit(\POSIX_RLIMIT_NOFILE, $needed, $hardValue)) {
                self::markTestSkipped("Soft open files limit is $soft and could not be raised to $needed");
            }

            $restore = [$soft, $hardValue];
        }

        $pairs = [];

        try {
            for ($i = 0; $i < $pairCount; $i++) {
                $pair = @\stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
                if ($pair === false) {
                    self::markTestSkipped("Could not open $pairCount socket pairs (failed at pair $i)");
                }

                \stream_set_blocking($pair[0], false);
                \stream_set_blocking($pair[1], false);
                $pairs[] = $pair;
            }

            [$low, $lowPeer] = $pairs[0];
            [$high, $highPeer] = $pairs[$pairCount - 1];
            \fwrite($lowPeer, 'low');
            \fwrite($highPeer, 'high');

            $received = [];
            $writable = false;

            $this->runBounded(function (Driver $loop) use ($low, $high, $highPeer, &$received, &$writable): void {
                $loop->onReadable($low, function (string $id, $stream) use ($loop, &$received): void {
                    $received['low'] = \fread($stream, 8192);
                    $loop->cancel($id);
                });

                $loop->onReadable($high, function (string $id, $stream) use ($loop, &$received): void {
                    $received['high'] = \fread($stream, 8192);
                    $loop->cancel($id);
                });

                $loop->onWritable($highPeer, function (string $id) use ($loop, &$writable): void {
                    $writable = true;
                    $loop->cancel($id);
                });
            }, 10.0);

            self::assertSame(['low' => 'low', 'high' => 'high'], $received);
            self::assertTrue($writable);
        } finally {
            foreach ($pairs as [$x, $y]) {
                \fclose($x);
                \fclose($y);
            }

            if ($restore !== null) {
                @\posix_setrlimit(\POSIX_RLIMIT_NOFILE, $restore[0], $restore[1]);
            }
        }
    }

    public function testUnselectableStreamFailsLikeStreamSelectDriver(): void
    {
        // php://memory can be watched by neither Io\Poll nor stream_select(). Like StreamSelectDriver, registering
        // succeeds and the next dispatch throws the stream_select() warning as an exception out of run().
        $memory = \fopen('php://memory', 'w+');

        [$a, $b] = $this->socketPair();
        \fwrite($b, 'x');

        $fired = false;
        $thrown = null;

        try {
            $this->runBounded(function (Driver $loop) use ($memory, $a, &$fired): void {
                $loop->onReadable($memory, function (string $id) use ($loop, &$fired): void {
                    $fired = true;
                    $loop->cancel($id);
                });

                $loop->onReadable($a, function (string $id, $stream) use ($loop): void {
                    \fread($stream, 8192);
                    $loop->cancel($id);
                });
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertFalse($fired, 'A php://memory callback must never fire');
        self::assertInstanceOf(\Exception::class, $thrown);
        self::assertSame(\Exception::class, $thrown::class);
        self::assertSame(
            'stream_select(): Cannot represent a stream of type MEMORY as a select()able descriptor',
            $thrown->getMessage()
        );
    }

    public function testStreamRejectedByBackendUsesFallbackPath(): void
    {
        // /dev/null is rejected by kqueue (and epoll) and never reported by macOS poll(2), but stream_select()
        // handles it, so its callback fires through the driver's fallback next to a backend-watched socket.
        $null = \fopen('/dev/null', 'r');

        [$a, $b] = $this->socketPair();
        \fwrite($b, 'x');

        $fired = ['null' => 0, 'socket' => 0];

        $this->runBounded(function (Driver $loop) use ($null, $a, &$fired): void {
            $loop->onReadable($null, function (string $id) use ($loop, &$fired): void {
                $fired['null']++;
                $loop->cancel($id);
            });

            $loop->onReadable($a, function (string $id, $stream) use ($loop, &$fired): void {
                $fired['socket']++;
                \fread($stream, 8192);
                $loop->cancel($id);
            });
        });

        self::assertSame(['null' => 1, 'socket' => 1], $fired);
    }

    public function testCharacterDeviceStreamGetsItsCallback(): void
    {
        $null = \fopen('/dev/null', 'r');
        $fired = 0;

        $this->runBounded(function (Driver $loop) use ($null, &$fired): void {
            $loop->onReadable($null, function (string $id) use ($loop, &$fired): void {
                $fired++;
                $loop->cancel($id);
            });
        });

        self::assertSame(1, $fired);
    }

    /**
     * STDIN redirected from /dev/null, as in a daemon or a CI job, is a character device that is always readable. The
     * check runs in a child process whose STDIN is /dev/null, so it does not depend on how the suite was started (a
     * terminal or a pipe as the suite's STDIN would make it unprovable here).
     */
    public function testStdinGetsItsCallbackWhenItIsAlwaysReadable(): void
    {
        $code = '$loop = new Fledge\Loop\Driver\PollDriver(Io\Poll\Backend::' . $this->backend()->name . ');' . <<<'PHP'

            $fired = 0;
            $loop->onReadable(STDIN, function (string $id) use ($loop, &$fired): void {
                $fired++;
                $loop->cancel($id);
            });
            $loop->unreference($loop->delay(5, static fn () => $loop->stop()));
            $loop->run();
            echo json_encode(['fired' => $fired]);
            PHP;

        $result = \runPhpSubprocess($code, timeout: 20.0, stdin: '/dev/null');

        self::assertFalse($result['timedOut'], 'child timed out; stderr: ' . $result['stderr']);
        self::assertSame(0, $result['exit'], 'stdout: ' . $result['stdout'] . ' stderr: ' . $result['stderr']);
        self::assertSame(['fired' => 1], \json_decode($result['stdout'], true));
    }

    /**
     * Regular files always poll ready (POSIX), for read and for write, empty, with unread data and at EOF; kqueue
     * alone reports a read only while unread bytes remain, and epoll refuses regular files.
     */
    public function testRegularFileIsReadyForReadAndWriteInEveryState(): void
    {
        $files = [
            'empty' => static fn () => \tmpfile(),
            'unread data' => static function () {
                $file = \tmpfile();
                \fwrite($file, 'data');
                \rewind($file);

                return $file;
            },
            'at EOF' => static function () {
                $file = \tmpfile();
                \fwrite($file, 'data');

                return $file;
            },
        ];

        foreach ($files as $state => $open) {
            foreach (['read', 'write'] as $interest) {
                $file = $open();
                self::assertIsResource($file);
                $fired = 0;

                $this->runBounded(function (Driver $loop) use ($file, $interest, &$fired): void {
                    $callback = function (string $id) use ($loop, &$fired): void {
                        $fired++;
                        $loop->cancel($id);
                    };

                    $interest === 'read' ? $loop->onReadable($file, $callback) : $loop->onWritable($file, $callback);
                }, 1.0);

                \fclose($file);

                self::assertSame(1, $fired, "regular file ($state) not reported ready for $interest");
            }
        }
    }

    /**
     * Streams that stream_select() reads as regular files through their cast: php://temp (a small one is spilled to a
     * temporary file) and a user-space wrapper casting to a regular file (checked with stream_select() itself). Ready
     * every tick, at EOF too, on every backend (see PollDriver::fileType()).
     */
    public function testStreamsSelectReadsAsRegularFilesAreAlwaysReady(): void
    {
        CastingStreamWrapper::register();

        $streams = [
            'php://temp empty' => static fn () => \fopen('php://temp', 'r+'),
            'php://temp at EOF' => static function () {
                $temp = \fopen('php://temp', 'r+');
                \fwrite($temp, 'data');

                return $temp;
            },
            'php://temp spilled, at EOF' => static function () {
                $temp = \fopen('php://temp/maxmemory:16', 'r+');
                \fwrite($temp, \str_repeat('x', 64));

                return $temp;
            },
            'user wrapper casting to an empty file' => static function () {
                CastingStreamWrapper::$inner = \tmpfile();
                CastingStreamWrapper::$claimRegularFile = false;

                return \fopen('fledge-cast://file', 'r');
            },
            'URL wrapper (STREAM_IS_URL) casting to a file at EOF' => static function () {
                CastingStreamWrapper::$inner = \tmpfile();
                \fwrite(CastingStreamWrapper::$inner, 'data');
                CastingStreamWrapper::$claimRegularFile = false;

                return \fopen('fledge-cast-url://file', 'r');
            },
        ];

        foreach ($streams as $name => $open) {
            foreach (['read', 'write'] as $interest) {
                $stream = $open();
                self::assertIsResource($stream, $name);
                $fired = 0;

                $this->runBounded(function (Driver $loop) use ($stream, $interest, &$fired): void {
                    $callback = function (string $id) use ($loop, &$fired): void {
                        if (++$fired === 3) {
                            $loop->cancel($id);
                        }
                    };

                    $interest === 'read' ? $loop->onReadable($stream, $callback) : $loop->onWritable($stream, $callback);
                }, 1.0);

                self::assertSame(3, $fired, "$name not reported ready for $interest every tick");
            }
        }
    }

    /**
     * Nothing that can block is ever taken for an always-ready stream: idle pipes, FIFOs and sockets, and a
     * user-space wrapper casting to a socket while its stream_stat() claims a regular file.
     */
    public function testStreamsThatCanBlockAreNeverAlwaysReady(): void
    {
        CastingStreamWrapper::register();
        $dir = \sys_get_temp_dir() . '/fledge-loop-fifo-' . \getmypid();
        if (!\is_dir($dir)) {
            \mkdir($dir);
        }
        $server = \stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $keep = [];

        $streams = [
            'pipe' => static function () use (&$keep) {
                $process = \proc_open(['sleep', '5'], [1 => ['pipe', 'w']], $pipes);
                $keep[] = $process;

                return $pipes[1];
            },
            'FIFO' => static function () use ($dir) {
                $path = $dir . '/fifo' . \random_int(0, \PHP_INT_MAX);
                \posix_mkfifo($path, 0600);

                return \fopen($path, 'r+');
            },
            'tcp socket' => static fn () => \stream_socket_client('tcp://' . \stream_socket_get_name($server, false)),
            'unix socket' => static function () use (&$keep) {
                $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
                $keep[] = $pair;

                return $pair[0];
            },
            'udp socket' => static fn () => \stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, \STREAM_SERVER_BIND),
            'user wrapper casting to a socket, claiming a regular file' => static function () use (&$keep) {
                $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
                $keep[] = $pair;
                CastingStreamWrapper::$inner = $pair[0];
                CastingStreamWrapper::$claimRegularFile = true;

                return \fopen('fledge-cast://pipe', 'r');
            },
        ];

        if (!\function_exists('posix_mkfifo')) {
            unset($streams['FIFO']);
        }

        foreach ($streams as $name => $open) {
            $stream = $open();
            self::assertIsResource($stream, $name);
            $fired = 0;
            $loop = $this->loop;

            $id = $loop->onReadable($stream, function () use (&$fired): void {
                $fired++;
            });
            $loop->delay(0.1, static fn () => $loop->cancel($id));
            $loop->run();

            self::assertSame(0, $fired, "idle $name reported readable");
        }

        CastingStreamWrapper::$inner = null;
        CastingStreamWrapper::$claimRegularFile = false;

        foreach ($keep as $held) {
            if (\is_resource($held) && \get_resource_type($held) === 'process') {
                \proc_terminate($held, 9);
            }
        }

        foreach (\glob($dir . '/fifo*') ?: [] as $fifo) {
            \unlink($fifo);
        }
    }

    /**
     * A user-space wrapper whose stream_stat() claims a regular file while its cast is a socket, registered while data
     * is pending: it is never taken for an always-ready stream. Its callback fires once per readiness (two writes, two
     * calls) and the loop blocks in between instead of spinning.
     */
    public function testLyingUserSpaceWrapperWithPendingDataFiresOncePerReadiness(): void
    {
        $this->assertLyingWrapperFiresOncePerReadiness('fledge-cast');
    }

    /**
     * The same with the wrapper registered as a URL wrapper (STREAM_IS_URL), which stream_is_local() reports as not
     * local: it must take the select-checked path too.
     */
    public function testLyingUrlWrapperWithPendingDataFiresOncePerReadiness(): void
    {
        $this->assertLyingWrapperFiresOncePerReadiness('fledge-cast-url');
    }

    private function assertLyingWrapperFiresOncePerReadiness(string $protocol): void
    {
        CastingStreamWrapper::register();
        [$inner, $peer] = $this->socketPair();
        CastingStreamWrapper::$inner = $inner;
        CastingStreamWrapper::$claimRegularFile = true;

        try {
            $stream = \fopen($protocol . '://liar', 'r');
            self::assertIsResource($stream);
        } finally {
            CastingStreamWrapper::$inner = null;
            CastingStreamWrapper::$claimRegularFile = false;
        }

        \fwrite($peer, 'first');
        $received = [];
        $cpu = static function (): float {
            $usage = \getrusage();

            return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
                + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
        };
        $before = $cpu();

        $this->runBounded(function (Driver $loop) use ($stream, $peer, &$received): void {
            $id = $loop->onReadable($stream, static function (string $id, $stream) use (&$received): void {
                $received[] = \fread($stream, 8192);
            });

            $loop->delay(0.1, static function () use ($peer): void {
                \fwrite($peer, 'second');
            });
            $loop->delay(0.3, static fn () => $loop->cancel($id));
        }, 2.0);

        $spent = $cpu() - $before;
        \fclose($stream);

        self::assertSame(['first', 'second'], $received);
        // Blocking for 0.3 s costs about a millisecond of CPU; spinning on an always-ready stream costs all of it.
        self::assertLessThan(0.1, $spent);
    }

    public function testRegularFileStaysReadyNextToAnIdleSocket(): void
    {
        [$a] = $this->socketPair();
        $file = \tmpfile();
        self::assertIsResource($file);
        $fired = 0;

        $this->runBounded(function (Driver $loop) use ($a, $file, &$fired): void {
            $socketId = $loop->onReadable($a, static function (): void {
            });
            $loop->onReadable($file, function (string $id) use ($loop, $socketId, &$fired): void {
                if (++$fired === 3) {
                    $loop->cancel($id);
                    $loop->cancel($socketId);
                }
            });
        }, 1.0);

        \fclose($file);

        self::assertSame(3, $fired);
    }

    public function testSignalDeliveredWhileLoopIsBlockedInWait(): void
    {
        if (!\extension_loaded('pcntl') || !\extension_loaded('posix')) {
            self::markTestSkipped('pcntl and posix are required to deliver signals');
        }

        $order = [];

        $this->runBounded(function (Driver $loop) use (&$order): void {
            $signalId = $loop->onSignal(\SIGUSR1, function () use (&$order): void {
                $order[] = 'signal';
            });

            $loop->delay(1.0, function () use ($loop, $signalId, &$order): void {
                $order[] = 'timer';
                $loop->cancel($signalId);
            });

            // The loop is parked in its wait for the 1 second timer when the child delivers the signal.
            $loop->defer(static function (): void {
                \proc_open('sh -c "sleep 0.3; kill -USR1 ' . \getmypid() . '"', [], $pipes);
            });
        });

        self::assertSame(['signal', 'timer'], $order);
    }

    public function testSignalDispatchedFromTheLoopWithoutAsyncSignals(): void
    {
        if (!\extension_loaded('pcntl') || !\extension_loaded('posix')) {
            self::markTestSkipped('pcntl and posix are required to deliver signals');
        }

        \pcntl_async_signals(true);

        try {
            $invoked = false;

            $this->runBounded(function (Driver $loop) use (&$invoked): void {
                $signalId = $loop->onSignal(\SIGUSR1, function () use (&$invoked): void {
                    $invoked = true;
                });

                $loop->defer(function () use ($loop, $signalId): void {
                    \posix_kill(\getmypid(), \SIGUSR1);

                    $loop->defer(function () use ($loop, $signalId): void {
                        $loop->defer(function () use ($loop, $signalId): void {
                            $loop->cancel($signalId);
                        });
                    });
                });
            });

            self::assertTrue($invoked);
        } finally {
            \pcntl_async_signals(false);
        }
    }

    public function testPeerCloseWakesReadableCallbackWithEof(): void
    {
        [$a, $b] = $this->socketPair();
        \fwrite($b, 'bye');

        $received = '';
        $eof = false;

        $this->runBounded(function (Driver $loop) use ($a, $b, &$received, &$eof): void {
            $loop->onReadable($a, function (string $id, $stream) use ($loop, &$received, &$eof): void {
                $chunk = \fread($stream, 8192);
                if ($chunk !== false) {
                    $received .= $chunk;
                }

                if ($chunk === '' && \feof($stream)) {
                    $eof = true;
                    $loop->cancel($id);
                }
            });

            $loop->defer(static function () use ($b): void {
                \fclose($b);
            });
        });

        self::assertSame('bye', $received);
        self::assertTrue($eof, 'The readable callback must fire for a hung up peer and see EOF');
    }
}
