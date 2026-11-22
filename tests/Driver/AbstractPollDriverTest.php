<?php

declare(strict_types=1);

/*
 * Ported from revolt/event-loop v1.1.0, test/Driver/IoPollDriverTest.php (MIT): testNoMemoryLeak, testHandle,
 * testAsyncSignals and testSupportedIfTestsAreNotSkipped, retargeted to PollDriver. The other IoPollDriverTest cases
 * (signal interrupting wait, descriptors above FD_SETSIZE) are covered by PollDriverTargetedTests.
 */

namespace Fledge\Loop\Tests\Driver;

use Fledge\Loop\Driver\PollDriver;
use Fledge\Loop\Driver;
use Io\Poll\Backend;
use Io\Poll\Context;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\ExpectationFailedException;

require_once __DIR__ . '/DriverTest.php';
require_once __DIR__ . '/PollDriverTargetedTests.php';

/**
 * Runs the generic driver suite plus the targeted tests against PollDriver on one Io\Poll backend.
 * Concrete subclasses name the backend; the whole class is skipped where that backend is unavailable.
 */
abstract class AbstractPollDriverTest extends DriverTest
{
    use PollDriverTargetedTests;

    abstract protected function backend(): Backend;

    public function getFactory(): callable
    {
        $backend = $this->backend();

        return static fn (): PollDriver => new PollDriver($backend);
    }

    public function setUp(): void
    {
        if (!PollDriver::isSupported()) {
            self::markTestSkipped('PollDriver is not supported on this build');
        }

        if (!$this->backend()->isAvailable()) {
            self::markTestSkipped('Io\Poll backend ' . $this->backend()->name . ' is not available on this platform');
        }

        parent::setUp();
    }

    #[DataProvider('provideRegistrationArgs')]
    #[Group('memoryleak')]
    public function testNoMemoryLeak(string $type, array $args): void
    {
        try {
            parent::testNoMemoryLeak($type, $args);
        } catch (ExpectationFailedException $exception) {
            // the kqueue backend leaks on every Watcher::modifyEvents(), see https://github.com/php/php-src/issues/25086
            if (\in_array($type, ['onReadable', 'onWritable'], true)
                && $this->loop->getHandle()->getBackend() === Backend::Kqueue
            ) {
                self::markTestSkipped('Io\\Poll leaks memory with kqueue, see php/php-src#25086: ' . $exception->getMessage());
            }

            throw $exception;
        }
    }

    public function testHandle(): void
    {
        self::assertInstanceOf(Context::class, $this->loop->getHandle());
    }

    public function testAsyncSignals(): void
    {
        if (self::isWindows()) {
            self::markTestSkipped('Skip on Windows');
        }

        if (!\extension_loaded('pcntl')
            || !\function_exists('pcntl_signal_dispatch')
            || !\function_exists('pcntl_signal')) {
            self::markTestSkipped('Skip, PCNTL functions not available');
        }

        \pcntl_async_signals(true);

        try {
            $this->start(function (Driver $loop) use (&$invoked, &$callbackId) {
                $callbackId = $loop->onSignal(SIGUSR1, function () use (&$invoked) {
                    $invoked = true;
                });

                $loop->defer(function () use ($loop, $callbackId) {
                    \posix_kill(\getmypid(), \SIGUSR1);

                    // Two defers, because defer is queued in the first tick and signals only after signals have been
                    // processed, so the second tick dispatches the signal. At the start of the third tick, we're done!
                    $loop->defer(function () use ($loop, $callbackId) {
                        $loop->defer(function () use ($loop, $callbackId) {
                            $loop->cancel($callbackId);
                        });
                    });
                });
            });
        } finally {
            \pcntl_async_signals(false);
        }

        self::assertTrue($invoked);

        $this->loop->cancel($callbackId);
    }

    public function testSupportedIfTestsAreNotSkipped(): void
    {
        self::assertTrue(PollDriver::isSupported());
    }
}
