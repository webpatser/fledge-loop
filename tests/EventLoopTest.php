<?php

declare(strict_types=1);

/*
 * Ported from revolt/event-loop v1.1.0, test/EventLoopTest.php (MIT).
 */

namespace Fledge\Loop\Tests;

use PHPUnit\Framework\TestCase;
use Fledge\Loop;
use Fledge\Loop\CallbackType;
use Fledge\Loop\Driver;
use Fledge\Loop\Suspension;
use Fledge\Loop\UncaughtThrowable;

class EventLoopTest extends TestCase
{
    public function testSuspensionResumptionWithQueueInGarbageCollection(): void
    {
        $suspension = Loop::getSuspension();

        $class = new class ($suspension) {
            public function __construct(public Suspension $suspension)
            {
            }
            public function __destruct()
            {
                $this->suspension->resume(true);
            }
        };
        $cycle = [$class, &$cycle];
        unset($class, $cycle);

        $ended = $suspension->suspend();

        $this->assertTrue($ended);
    }

    public function testEventLoopResumptionWithQueueInGarbageCollection(): void
    {
        $suspension = Loop::getSuspension();

        $class = new class ($suspension) {
            public function __construct(public Suspension $suspension)
            {
            }
            public function __destruct()
            {
                Loop::queue($this->suspension->resume(...), true);
            }
        };
        $cycle = [$class, &$cycle];
        unset($class, $cycle);

        $ended = $suspension->suspend();

        $this->assertTrue($ended);
    }


    public function testSuspensionResumptionWithQueueInGarbageCollectionNested(): void
    {
        $suspension = Loop::getSuspension();

        $resumer = new class ($suspension) {
            public function __construct(public Suspension $suspension)
            {
            }
            public function __destruct()
            {
                $this->suspension->resume(true);
            }
        };

        $class = new class ($resumer) {
            public static ?object $staticReference = null;
            public function __construct(object $resumer)
            {
                self::$staticReference = $resumer;
            }
            public function __destruct()
            {
                Loop::queue(function () {
                    $class = self::$staticReference;
                    $cycle = [$class, &$cycle];
                    unset($class, $cycle);

                    self::$staticReference = null;
                });
            }
        };
        $cycle = [$class, &$cycle];
        unset($class, $resumer, $cycle);


        $ended = $suspension->suspend();

        $this->assertTrue($ended);
    }

    public function testEventLoopResumptionWithQueueInGarbageCollectionNested(): void
    {
        $suspension = Loop::getSuspension();

        $resumer = new class ($suspension) {
            public function __construct(public Suspension $suspension)
            {
            }
            public function __destruct()
            {
                Loop::queue($this->suspension->resume(...), true);
            }
        };

        $class = new class ($resumer) {
            public static ?object $staticReference = null;
            public function __construct(object $resumer)
            {
                self::$staticReference = $resumer;
            }
            public function __destruct()
            {
                Loop::queue(function () {
                    $class = self::$staticReference;
                    $cycle = [$class, &$cycle];
                    unset($class, $cycle);

                    self::$staticReference = null;
                });
            }
        };
        $cycle = [$class, &$cycle];
        unset($class, $resumer, $cycle);


        $ended = $suspension->suspend();

        $this->assertTrue($ended);
    }


    public function testDelayWithNegativeDelay(): void
    {
        $this->expectException(\Error::class);

        Loop::delay(-1, fn () => null);
    }

    public function testRepeatWithNegativeInterval(): void
    {
        $this->expectException(\Error::class);

        Loop::repeat(-1, fn () => null);
    }

    public function testOnReadable(): void
    {
        $ends = \stream_socket_pair(
            \DIRECTORY_SEPARATOR === "\\" ? STREAM_PF_INET : STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP
        );
        \fwrite($ends[0], "trigger readability callback");

        $count = 0;
        $suspension = Loop::getSuspension();

        Loop::onReadable($ends[1], function ($callbackId) use (&$count, $suspension): void {
            $this->assertTrue(true);
            Loop::cancel($callbackId);
            $count++;

            $suspension->resume();
        });

        $suspension->suspend();

        self::assertSame(1, $count);
    }

    public function testOnWritable(): void
    {
        $count = 0;
        $suspension = Loop::getSuspension();

        Loop::onWritable(STDOUT, function ($callbackId) use (&$count, $suspension): void {
            $this->assertTrue(true);
            Loop::cancel($callbackId);
            $count++;

            $suspension->resume();
        });

        $suspension->suspend();

        self::assertSame(1, $count);
    }

    public function testGet(): void
    {
        self::assertInstanceOf(Driver::class, Loop::getDriver());
    }

    public function testReflection(): void
    {
        self::assertSame([], Loop::getIdentifiers());

        $id = Loop::delay(5, fn () => null);

        self::assertSame([$id], Loop::getIdentifiers());
        self::assertSame(CallbackType::Delay, Loop::getType($id));
        self::assertTrue(Loop::isReferenced($id));
        self::assertTrue(Loop::isEnabled($id));

        Loop::disable($id);
        self::assertFalse(Loop::isEnabled($id));
        self::assertTrue(Loop::isReferenced($id));

        Loop::unreference($id);
        self::assertFalse(Loop::isEnabled($id));
        self::assertFalse(Loop::isReferenced($id));

        Loop::enable($id);
        self::assertTrue(Loop::isEnabled($id));
        self::assertFalse(Loop::isReferenced($id));

        Loop::reference($id);
        self::assertTrue(Loop::isEnabled($id));
        self::assertTrue(Loop::isReferenced($id));

        Loop::cancel($id);

        self::assertSame([], Loop::getIdentifiers());
    }

    public function testRun(): void
    {
        $invoked = false;
        Loop::defer(function () use (&$invoked): void {
            $invoked = true;
        });

        Loop::run();

        self::assertTrue($invoked);
    }

    public function testFiberReuse(): void
    {
        Loop::defer(function () use (&$fiber1): void {
            $fiber1 = \Fiber::getCurrent();
        });

        Loop::defer(function () use (&$fiber2): void {
            $fiber2 = \Fiber::getCurrent();
        });

        Loop::run();

        self::assertNotNull($fiber1);
        self::assertNotNull($fiber2);
        self::assertSame($fiber1, $fiber2);
    }

    public function testRunInFiber(): void
    {
        Loop::queue(fn () => Loop::run());

        $this->expectException(\Error::class);
        $this->expectExceptionMessage("The event loop is already running");

        Loop::run();
    }

    public function testRunAfterSuspension(): void
    {
        $suspension = Loop::getSuspension();

        Loop::defer(fn () => $suspension->resume('test'));

        self::assertSame($suspension->suspend(), 'test');

        $invoked = false;
        Loop::defer(function () use (&$invoked): void {
            $invoked = true;
        });

        Loop::run();

        self::assertTrue($invoked);
    }

    public function testSuspensionAfterRun(): void
    {
        $invoked = false;
        Loop::defer(function () use (&$invoked): void {
            $invoked = true;
        });

        Loop::run();

        self::assertTrue($invoked);

        $suspension = Loop::getSuspension();

        Loop::defer(fn () => $suspension->resume('test'));

        self::assertSame($suspension->suspend(), 'test');
    }

    public function testSuspensionWithinFiber(): void
    {
        $invoked = false;

        Loop::queue(function () use (&$invoked): void {
            $suspension = Loop::getSuspension();

            Loop::defer(fn () => $suspension->resume('test'));

            self::assertSame($suspension->suspend(), 'test');

            $invoked = true;
        });

        Loop::run();

        self::assertTrue($invoked);
    }

    public function testDoubleResumeWithinFiber(): void
    {
        $suspension = Loop::getSuspension();

        Loop::queue(static function () use ($suspension): void {
            $suspension->resume();
            $suspension->resume();
        });

        $this->expectException(UncaughtThrowable::class);
        $this->expectExceptionMessage('Must call suspend() before calling resume()');

        $suspension->suspend();
    }

    public function testSuspensionWithinCallback(): void
    {
        $send = 42;

        Loop::defer(static function () use (&$received, $send): void {
            $suspension = Loop::getSuspension();
            Loop::defer(static fn () => $suspension->resume($send));
            $received = $suspension->suspend();
        });

        Loop::run();

        self::assertSame($send, $received);
    }

    public function testSuspensionWithinCallbackGarbageCollection(): void
    {
        // Measurement differs from the original, which asserted memory_get_usage() stayed exactly the same every 250
        // iterations: any one-off allocator step (a table crossing a size class) failed it. Here the growth after a
        // warm-up is divided by the iterations measured; a suspension, fiber or closure retained per iteration costs
        // hundreds of bytes, a one-off step of 64 KiB less than 8 bytes per iteration.
        $memory = 0;
        $i = 0;

        Loop::repeat(0, static function (string $id) use (&$memory, &$i) {
            $suspension = Loop::getSuspension();
            Loop::defer(static fn () => $suspension->resume());
            $suspension->suspend();

            if (++$i === 1000) {
                \gc_collect_cycles();
                $memory = \memory_get_usage();
            }

            if ($i === 10000) {
                Loop::cancel($id);
            }
        });

        Loop::run();

        \gc_collect_cycles();
        $bytesPerIteration = (\memory_get_usage() - $memory) / 9000;

        self::assertLessThan(32, $bytesPerIteration, \sprintf('%.1f bytes retained per iteration', $bytesPerIteration));
    }

    public function testSuspensionWithinCallbackGarbageCollectionSuspended(): void
    {
        Loop::defer(static function () use (&$finally): void {
            $suspension = Loop::getSuspension();

            try {
                $suspension->suspend();
            } finally {
                $finally = true;
            }
        });

        Loop::run();

        \gc_collect_cycles();

        self::assertTrue($finally);
    }

    public function testSuspensionWithinQueue(): void
    {
        $send = 42;

        Loop::queue(static function () use (&$received, $send): void {
            $suspension = Loop::getSuspension();
            Loop::defer(static fn () => $suspension->resume($send));
            $received = $suspension->suspend();
        });

        Loop::run();

        self::assertSame($send, $received);
    }

    public function testResumedQueueFibersDoNotNestInEachOther(): void
    {
        $fibers = 25;
        $suspensions = [];
        $depths = [];

        for ($i = 0; $i < $fibers; $i++) {
            Loop::queue(static function () use (&$suspensions, &$depths): void {
                $suspension = Loop::getSuspension();
                $suspensions[] = $suspension;
                $suspension->suspend();

                $exception = new \Exception();
                $depths[] = \count($exception->getTrace());
            });
        }

        Loop::defer(static function () use (&$suspensions): void {
            foreach ($suspensions as $suspension) {
                $suspension->resume();
            }
        });

        Loop::run();

        self::assertCount($fibers, $depths);
        self::assertCount(1, \array_unique($depths));
    }

    public function testResumedCallbackFibersDoNotNestInEachOther(): void
    {
        $fibers = 25;
        $suspensions = [];
        $depths = [];

        for ($i = 0; $i < $fibers; $i++) {
            Loop::defer(static function () use (&$suspensions, &$depths): void {
                $suspension = Loop::getSuspension();
                $suspensions[] = $suspension;
                $suspension->suspend();

                $exception = new \Exception();
                $depths[] = \count($exception->getTrace());
            });
        }

        Loop::delay(0, static function () use (&$suspensions): void {
            foreach ($suspensions as $suspension) {
                $suspension->resume();
            }
        });

        Loop::run();

        self::assertCount($fibers, $depths);
        self::assertCount(1, \array_unique($depths));
    }

    public function testDeferredCallbackDoesNotRunInsideResumedFiber(): void
    {
        Loop::queue(static function () use (&$suspension): void {
            $suspension = Loop::getSuspension();
            $suspension->suspend();
        });

        Loop::defer(static function () use (&$suspension, &$resumingDepth): void {
            $exception = new \Exception();
            $resumingDepth = \count($exception->getTrace());

            $suspension->resume();
        });

        Loop::defer(static function () use (&$nextDepth): void {
            $exception = new \Exception();
            $nextDepth = \count($exception->getTrace());
        });

        Loop::run();

        self::assertSame($resumingDepth, $nextDepth);
    }

    public function testResumedFibersRunInOrderWithQueuedMicrotasks(): void
    {
        $order = [];

        Loop::queue(static function () use (&$first, &$order): void {
            $first = Loop::getSuspension();
            $first->suspend();
            $order[] = 'first';
        });

        Loop::queue(static function () use (&$second, &$order): void {
            $second = Loop::getSuspension();
            $second->suspend();
            $order[] = 'second';
        });

        Loop::defer(static function () use (&$first, &$second, &$order): void {
            $first->resume();
            Loop::queue(static function () use (&$order): void {
                $order[] = 'microtask';
            });
            $second->resume();
        });

        Loop::run();

        self::assertSame(['first', 'microtask', 'second'], $order);
    }

    public function testExceptionFromResumedFiberLeavesNextMicrotaskToNextRun(): void
    {
        $exception = new \Exception();
        $invoked = false;

        Loop::queue(static function () use (&$suspension, $exception): void {
            $suspension = Loop::getSuspension();
            $suspension->suspend();

            throw $exception;
        });

        Loop::defer(static function () use (&$suspension, &$invoked): void {
            $suspension->resume();
            Loop::queue(static function () use (&$invoked): void {
                $invoked = true;
            });
        });

        try {
            Loop::run();
            self::fail('The exception must leave the event loop');
        } catch (UncaughtThrowable $throwable) {
            self::assertSame($exception, $throwable->getPrevious());
        }

        self::assertFalse($invoked);

        Loop::run();

        self::assertTrue($invoked);
    }

    public function testFiberResumedDirectlyFromMainLeavesMicrotasksToNextRun(): void
    {
        $log = [];

        Loop::defer(static function () use (&$fiber, &$log): void {
            $fiber = \Fiber::getCurrent();
            \Fiber::suspend();

            Loop::queue(static function () use (&$log): void {
                $log[] = 'microtask';
            });

            $log[] = 'callback';
        });

        Loop::run();

        $fiber->resume();
        $log[] = 'resumed';

        Loop::run();

        self::assertSame(['callback', 'resumed', 'microtask'], $log);
        self::assertTrue($fiber->isTerminated());
    }

    public function testFiberResumedDirectlyFromCallbackLeavesMicrotasksUntilCallbackReturns(): void
    {
        $log = [];

        Loop::defer(static function () use (&$fiber, &$log): void {
            $fiber = \Fiber::getCurrent();
            \Fiber::suspend();

            Loop::queue(static function () use (&$log): void {
                $log[] = 'microtask';
            });

            $log[] = 'callback';
        });

        Loop::delay(0, static function () use (&$fiber, &$log): void {
            $fiber->resume();
            $log[] = 'resumed';
        });

        Loop::run();

        self::assertSame(['callback', 'resumed', 'microtask'], $log);
        self::assertTrue($fiber->isTerminated());
    }

    public function testTimerCallbackDoesNotRunInsideDirectlyResumedFiber(): void
    {
        $log = [];

        Loop::defer(static function () use (&$fiber, &$log): void {
            $fiber = \Fiber::getCurrent();
            \Fiber::suspend();

            $log[] = 'callback';
        });

        Loop::delay(0, static function () use (&$fiber, &$log): void {
            $fiber->resume();
            $log[] = 'resumed';
        });

        Loop::delay(0, static function () use (&$log): void {
            $log[] = 'other callback';
        });

        Loop::run();

        self::assertSame(['callback', 'resumed', 'other callback'], $log);
        self::assertTrue($fiber->isTerminated());
    }

    public function testDeferredCallbackDoesNotRunInsideFiberSuspendedByDestructor(): void
    {
        // Suspending in a destructor replaces the callback fiber the destructor runs in.
        $suspendOnDestruct = new class (static function () use (&$replacedFiber, &$suspension): void {
            $replacedFiber = \Fiber::getCurrent();
            $suspension = Loop::getSuspension();
            $suspension->suspend();
        }) {
            public function __construct(private readonly \Closure $onDestruct)
            {
            }

            public function __destruct()
            {
                ($this->onDestruct)();
            }
        };

        // Runs first and cancels the next callback while it is already queued for this tick.
        Loop::defer(static function () use (&$callbackId): void {
            Loop::cancel($callbackId);
        });

        $callbackId = Loop::defer(static function () use ($suspendOnDestruct): void {
            self::fail('Callback must not be invoked after being cancelled');
        });

        // The callback now holds the only reference, so the destructor runs when the loop discards it after the cancel.
        unset($suspendOnDestruct);

        Loop::defer(static function () use (&$suspension): void {
            $suspension->resume();
        });

        Loop::defer(static function () use (&$lastCallbackFiber): void {
            $lastCallbackFiber = \Fiber::getCurrent();
        });

        Loop::run();

        self::assertNotSame($replacedFiber, $lastCallbackFiber);
        self::assertTrue($replacedFiber->isTerminated());
    }

    public function testExceptionFromDirectlyResumedFiberIsThrownAfterResumingCallbackReturns(): void
    {
        $exception = new \Exception();
        $log = [];

        Loop::defer(static function () use (&$fiber, $exception): void {
            $fiber = \Fiber::getCurrent();
            \Fiber::suspend();

            throw $exception;
        });

        Loop::delay(0, static function () use (&$fiber, &$log): void {
            $fiber->resume();
            $log[] = 'resumed';

            Loop::queue(static function () use (&$log): void {
                $log[] = 'microtask';
            });
        });

        try {
            Loop::run();
            self::fail('The exception must leave the event loop');
        } catch (UncaughtThrowable $throwable) {
            self::assertSame($exception, $throwable->getPrevious());
        }

        // A raw Fiber::resume() cannot be stopped, so the resuming callback finishes before the interrupt is thrown.
        self::assertTrue($fiber->isTerminated());
        self::assertSame(['resumed'], $log);

        Loop::run();

        self::assertSame(['resumed', 'microtask'], $log);
    }

    public function testSuspensionThrowingErrorViaInterrupt(): void
    {
        $suspension = Loop::getSuspension();
        $error = new \Error("Test error");
        Loop::queue(static fn () => throw $error);
        try {
            $suspension->suspend();
            self::fail("Error was not thrown");
        } catch (UncaughtThrowable $t) {
            self::assertSame($error, $t->getPrevious());
        }

        $suspension->resume(); // Calling resume on the same suspension should not throw an Error.
        $suspension->throw(new \RuntimeException()); // Calling throw on the same suspension should not throw an Error.

        try {
            $suspension->suspend(); // Calling suspend on the same suspension should throw an Error.
            self::fail("Error was not thrown");
        } catch (\Error $e) {
            self::assertStringContainsString('suspended after an uncaught exception', $e->getMessage());
        }

        // Creating a new Suspension and re-entering the event loop (e.g. in a shutdown function) should work.
        $suspension = Loop::getSuspension();
        Loop::queue($suspension->resume(...));
        $suspension->suspend();
    }

    public function testSuspensionThrowingErrorViaInterrupt2(): void
    {
        $suspension = Loop::getSuspension();
        $error = new \Error("Test error");
        Loop::queue(static fn () => throw $error);
        Loop::queue($suspension->resume(...), 123);
        try {
            $suspension->suspend();
            self::fail("Error was not thrown");
        } catch (UncaughtThrowable $t) {
            self::assertSame($error, $t->getPrevious());
        }

        $suspension->resume(); // Calling resume on the same suspension should not throw an Error.
        $suspension->throw(new \RuntimeException()); // Calling throw on the same suspension should not throw an Error.

        try {
            $suspension->suspend(); // Calling suspend on the same suspension should throw an Error.
            self::fail("Error was not thrown");
        } catch (\Error $e) {
            self::assertStringContainsString('suspended after an uncaught exception', $e->getMessage());
        }

        // Creating a new Suspension and re-entering the event loop (e.g. in a shutdown function) should work.
        $suspension = Loop::getSuspension();
        Loop::queue($suspension->resume(...), 321);
        $this->assertEquals(321, $suspension->suspend());
    }

    public function testFiberDestroyedWhileSuspended(): void
    {
        $outer = new class (new class ($this) {
            private ?Suspension $suspension = null;

            public function __construct(public object $outer)
            {
            }

            public function suspend(): void
            {
                $this->suspension = Loop::getSuspension();
                $this->suspension->suspend();
            }

            public function __destruct()
            {
                echo 'object destroyed';
                $suspension = $this->suspension;
                $this->suspension = null;
                Loop::defer(static fn () => $suspension?->resume());
            }
        }) {
            public function __construct(public object $inner)
            {
            }
        };

        $inner = $outer->inner;
        unset($outer);

        Loop::queue(static fn () => $inner->suspend());
        unset($inner);

        Loop::queue(\gc_collect_cycles(...));

        $this->expectOutputString('object destroyed');

        Loop::run();
    }
}
