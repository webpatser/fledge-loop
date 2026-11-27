<?php

declare(strict_types=1);

// Derived from revolt/event-loop v1.1.0, src/EventLoop.php.
// MIT License; copyright notices in LICENSE.

namespace Fledge;

use Fledge\Loop\CallbackType;
use Fledge\Loop\Driver;
use Fledge\Loop\Driver\PollDriver;
use Fledge\Loop\Driver\StreamSelectDriver;
use Fledge\Loop\DriverFactory;
use Fledge\Loop\ForkFailedException;
use Fledge\Loop\ForkUnwindException;
use Fledge\Loop\Internal\AbstractDriver;
use Fledge\Loop\Internal\DriverCallback;
use Fledge\Loop\InvalidCallbackError;
use Fledge\Loop\LoopStateException;
use Fledge\Loop\Suspension;
use Fledge\Loop\UnknownDriverException;
use Fledge\Loop\UnsupportedFeatureException;
use Time\Duration;

/**
 * Accessor to allow global access to the event loop.
 *
 * Driver selection: the default driver is created lazily on first use through {@see DriverFactory}, which honours
 * the `FLEDGE_EVENT_LOOP` environment variable (`auto`, `poll` or `select`; an invalid value throws at that first
 * use). {@see self::install()} picks a driver explicitly.
 *
 * Which entry point:
 *
 * - {@see self::install()}: a process's own first setup, before its loop has run anything. Strict: it refuses while
 *   the loop is running or has callbacks registered.
 * - {@see self::afterFork()}: the first call in a child right after `pcntl_fork()` returned 0, from {main}, when the
 *   parent had already used the loop (a queue master that forks workers, a server that forks). It discards the
 *   inherited driver whatever its state and installs a fresh one.
 * - {@see self::fork()}: `pcntl_fork()` plus `afterFork()` in the child, in one call.
 *
 * What a forked child gets: an EMPTY loop. Every timer, stream watcher, deferred callback and loop signal handler of
 * the parent is gone; signal handlers the parent registered through the loop are reset to SIG_DFL. Callback ids the
 * parent handed out are dead in the child: the fresh driver continues the parent's id sequence, so such an id is
 * unknown rather than an alias for one of the child's callbacks (`cancel()`, `disable()` and `unreference()` are
 * no-ops for it, `enable()` throws). Objects built in the parent that rely on their own loop callbacks (servers,
 * timeout queues, pools, heartbeats) do not work in the child and must be built again there.
 *
 * Fork from a quiet point, with no fiber suspended mid-exchange on a connection: fibers suspended in the parent
 * unwind in the child (during `afterFork()`, or at the child's exit without it), and their `finally` blocks and
 * destructors can write to the parent's peer. That is a property of forking with live fibers, with or without
 * `afterFork()`.
 *
 * `afterFork()` is the only full protection for a forked child. Without it, the poll driver throws a LogicException
 * when the child syncs a stream watcher or waits on the inherited kernel descriptor, and an exit hook releases the
 * inherited descriptor before PHP closes the child's streams so that a plain child exit does not delete the parent's
 * registrations; that hook is best effort (a shutdown function registered before it runs first).
 *
 * @see Driver
 */
final class Loop
{
    private static Driver $driver;

    /**
     * The driver-less stand-in installed while the loop swaps drivers (setDriver(), install(), afterFork()), so that
     * garbage collected during the swap cannot reach the old driver; null outside a swap. Never reported as a driver.
     */
    private static ?Driver $placeholder = null;

    /**
     * Sets the driver to be used as the event loop.
     */
    public static function setDriver(Driver $driver): void
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck, RedundantCondition */
        if (isset(self::$driver) && self::$driver->isRunning()) {
            throw new \Error("Can't swap the event loop driver while the driver is running");
        }

        $previousPlaceholder = self::$placeholder;

        try {
            /** @psalm-suppress InternalClass */
            self::$driver = self::$placeholder = new class () extends AbstractDriver {
                #[\Override]
                protected function activate(array $callbacks): void
                {
                    throw new \Error("Can't activate callback during garbage collection.");
                }

                #[\Override]
                protected function dispatch(bool $blocking): void
                {
                    throw new \Error("Can't dispatch during garbage collection.");
                }

                #[\Override]
                protected function deactivate(DriverCallback $callback): void
                {
                    // do nothing
                }

                #[\Override]
                public function getHandle(): mixed
                {
                    return null;
                }

                #[\Override]
                protected function now(): float
                {
                    return (float) \hrtime(true) / 1_000_000_000;
                }
            };

            \gc_collect_cycles();
        } finally {
            self::$driver = $driver;
            self::$placeholder = $previousPlaceholder;
        }
    }

    /**
     * Queue a microtask.
     *
     * The queued callback MUST be executed immediately once the event loop gains control. Order of queueing MUST be
     * preserved when executing the callbacks. Recursive scheduling can thus result in infinite loops, use with care.
     *
     * Does NOT create an event callback, thus CAN NOT be marked as disabled or unreferenced.
     * Use {@see Loop::defer()} if you need these features.
     *
     * @param \Closure(...):void $closure The callback to queue.
     * @param mixed ...$args The callback arguments.
     */
    public static function queue(\Closure $closure, mixed ...$args): void
    {
        self::getDriver()->queue($closure, ...$args);
    }

    /**
     * Defer the execution of a callback.
     *
     * The deferred callback MUST be executed before any other type of callback in a tick. Order of enabling MUST be
     * preserved when executing the callbacks.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Deferred callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param \Closure(string):void $closure The callback to defer. The `$callbackId` will be
     *     invalidated before the callback invocation.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public static function defer(\Closure $closure): string
    {
        return self::getDriver()->defer($closure);
    }

    /**
     * Delay the execution of a callback.
     *
     * The delay is a minimum and approximate, accuracy is not guaranteed. Order of calls MUST be determined by which
     * timers expire first, but timers with the same expiration time MAY be executed in any order.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param Duration|float $delay The amount of time to delay the execution for, as a `Duration` or in seconds:
     *     zero or more (-0.0 counts as zero). A `Duration` is converted to float seconds once, on entry. Float INF
     *     schedules a timer that never fires; while referenced it keeps the loop running.
     * @param \Closure(string):void $closure The callback to delay. The `$callbackId` will be invalidated
     *     before the callback invocation.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     *
     * @throws \Error When the delay is negative (a negated `Duration` included) or NAN.
     */
    public static function delay(Duration|float $delay, \Closure $closure): string
    {
        return self::getDriver()->delay($delay, $closure);
    }

    /**
     * Repeatedly execute a callback.
     *
     * The interval between executions is a minimum and approximate, accuracy is not guaranteed. Order of calls MUST be
     * determined by which timers expire first, but timers with the same expiration time MAY be executed in any order.
     * The first execution is scheduled after the first interval period.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param Duration|float $interval The time interval to wait between executions, as a `Duration` or in seconds:
     *     zero or more (-0.0 counts as zero). A `Duration` is converted to float seconds once, on entry. Float INF
     *     schedules a timer that never fires; while referenced it keeps the loop running.
     * @param \Closure(string):void $closure The callback to repeat.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     *
     * @throws \Error When the interval is negative (a negated `Duration` included) or NAN.
     */
    public static function repeat(Duration|float $interval, \Closure $closure): string
    {
        return self::getDriver()->repeat($interval, $closure);
    }

    /**
     * Execute a callback when a stream resource becomes readable or is closed for reading.
     *
     * Contract: cancel a stream's callbacks before the stream is closed. That covers `fclose()` and `pclose()`, and
     * the cases where PHP closes a stream the caller still holds: `proc_close()` (and dropping the last reference to
     * a `proc_open()` process handle) closes that process's pipes, and `socket_close()` on an ext-sockets `Socket`
     * closes the stream it was imported from (`socket_import_stream()`) or exported to (`socket_export_stream()`).
     * Cancel the callbacks on a child's pipes before `proc_close()` or before letting the process handle go.
     * (`stream_socket_shutdown()`, `proc_terminate()`, dropping a `Socket` object and dropping a curl handle do not
     * close the stream.)
     *
     * Closing a stream while one of its callbacks is enabled is a programming error. In debug mode (assertions
     * active, or env FLEDGE_DEBUG truthy when the driver is created) PollDriver throws an \Error at the next tick,
     * naming the callbacks and the definition sites of their closures; it is thrown out of run() (or out of the
     * suspend() that is running the loop), not passed to the loop's error handler. StreamSelectDriver throws an \Error
     * at the next tick in every mode. In production PollDriver does not check every tick: a bounded safety-net sweep
     * finds such a stream late (with 20,000 watched streams within about 6 seconds, see
     * {@see \Fledge\Loop\Driver\PollDriver}) and then runs its callbacks as if it were ready.
     *
     * Multiple callbacks on the same stream MAY be executed in any order.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param resource $stream The stream to monitor.
     * @param \Closure(string, resource):void $closure The callback to execute.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public static function onReadable(mixed $stream, \Closure $closure): string
    {
        return self::getDriver()->onReadable($stream, $closure);
    }

    /**
     * Execute a callback when a stream resource becomes writable or is closed for writing.
     *
     * Contract: cancel a stream's callbacks before the stream is closed. That covers `fclose()` and `pclose()`, and
     * the cases where PHP closes a stream the caller still holds: `proc_close()` (and dropping the last reference to
     * a `proc_open()` process handle) closes that process's pipes, and `socket_close()` on an ext-sockets `Socket`
     * closes the stream it was imported from (`socket_import_stream()`) or exported to (`socket_export_stream()`).
     * Cancel the callbacks on a child's pipes before `proc_close()` or before letting the process handle go.
     * (`stream_socket_shutdown()`, `proc_terminate()`, dropping a `Socket` object and dropping a curl handle do not
     * close the stream.)
     *
     * Closing a stream while one of its callbacks is enabled is a programming error. In debug mode (assertions
     * active, or env FLEDGE_DEBUG truthy when the driver is created) PollDriver throws an \Error at the next tick,
     * naming the callbacks and the definition sites of their closures; it is thrown out of run() (or out of the
     * suspend() that is running the loop), not passed to the loop's error handler. StreamSelectDriver throws an \Error
     * at the next tick in every mode. In production PollDriver does not check every tick: a bounded safety-net sweep
     * finds such a stream late (with 20,000 watched streams within about 6 seconds, see
     * {@see \Fledge\Loop\Driver\PollDriver}) and then runs its callbacks as if it were ready.
     *
     * Multiple callbacks on the same stream MAY be executed in any order.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param resource $stream The stream to monitor.
     * @param \Closure(string, resource):void $closure The callback to execute.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public static function onWritable(mixed $stream, \Closure $closure): string
    {
        return self::getDriver()->onWritable($stream, $closure);
    }

    /**
     * Execute a callback when a signal is received.
     *
     * Warning: Installing the same signal on different instances of this interface is deemed undefined behavior.
     * Implementations MAY try to detect this, if possible, but are not required to. This is due to technical
     * limitations of the signals being registered globally per process.
     *
     * Multiple callbacks on the same signal MAY be executed in any order.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * The handler is installed with the kernel at that next tick, not when onSignal() returns, and cancelling (or
     * disabling) the last callback for a signal restores its default disposition. A process that must not die of an
     * early SIGTERM should let the loop run one tick before it advertises readiness.
     *
     * @param int $signal The signal number to monitor.
     * @param \Closure(string, int):void $closure The callback to execute.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     *
     * @throws UnsupportedFeatureException If signal handling is not supported.
     */
    public static function onSignal(int $signal, \Closure $closure): string
    {
        return self::getDriver()->onSignal($signal, $closure);
    }

    /**
     * Enable a callback to be active starting in the next tick.
     *
     * Callbacks MUST immediately be marked as enabled, but only be activated (i.e. callbacks can be called) right
     * before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     *
     * @throws InvalidCallbackError If the callback identifier is invalid.
     */
    public static function enable(string $callbackId): string
    {
        return self::getDriver()->enable($callbackId);
    }

    /**
     * Disable a callback immediately.
     *
     * A callback MUST be disabled immediately, e.g. if a deferred callback disables another deferred callback,
     * the second deferred callback isn't executed in this tick.
     *
     * Disabling a callback MUST NOT invalidate the callback. Calling this function MUST NOT fail, even if passed an
     * invalid callback identifier.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     */
    public static function disable(string $callbackId): string
    {
        return self::getDriver()->disable($callbackId);
    }

    /**
     * Cancel a callback.
     *
     * This will detach the event loop from all resources that are associated to the callback. After this operation the
     * callback is permanently invalid. Calling this function MUST NOT fail, even if passed an invalid identifier.
     *
     * @param string $callbackId The callback identifier.
     */
    public static function cancel(string $callbackId): void
    {
        self::getDriver()->cancel($callbackId);
    }

    /**
     * Reference a callback.
     *
     * This will keep the event loop alive whilst the event is still being monitored. Callbacks have this state by
     * default.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     *
     * @throws InvalidCallbackError If the callback identifier is invalid.
     */
    public static function reference(string $callbackId): string
    {
        return self::getDriver()->reference($callbackId);
    }

    /**
     * Unreference a callback.
     *
     * The event loop should exit the run method when only unreferenced callbacks are still being monitored. Callbacks
     * are all referenced by default.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     */
    public static function unreference(string $callbackId): string
    {
        return self::getDriver()->unreference($callbackId);
    }

    /**
     * Set a callback to be executed when an error occurs.
     *
     * The callback receives the error as the first and only parameter. The return value of the callback gets ignored.
     * If it can't handle the error, it MUST throw the error. Errors thrown by the callback or during its invocation
     * MUST be thrown into the `run` loop and stop the driver.
     *
     * Subsequent calls to this method will overwrite the previous handler.
     *
     * @param null|\Closure(\Throwable):void $errorHandler The callback to execute. `null` will clear the current handler.
     */
    public static function setErrorHandler(?\Closure $errorHandler): void
    {
        self::getDriver()->setErrorHandler($errorHandler);
    }

    /**
     * Gets the error handler closure or {@code null} if none is set.
     *
     * @return null|\Closure(\Throwable):void The previous handler, `null` if there was none.
     */
    public static function getErrorHandler(): ?\Closure
    {
        return self::getDriver()->getErrorHandler();
    }

    /**
     * Returns all registered non-cancelled callback identifiers.
     *
     * @return string[] Callback identifiers.
     */
    public static function getIdentifiers(): array
    {
        return self::getDriver()->getIdentifiers();
    }

    /**
     * Returns the type of the callback identified by the given callback identifier.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return CallbackType The callback type.
     */
    public static function getType(string $callbackId): CallbackType
    {
        return self::getDriver()->getType($callbackId);
    }

    /**
     * Returns whether the callback identified by the given callback identifier is currently enabled.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return bool `true` if the callback is currently enabled, otherwise `false`.
     */
    public static function isEnabled(string $callbackId): bool
    {
        return self::getDriver()->isEnabled($callbackId);
    }

    /**
     * Returns whether the callback identified by the given callback identifier is currently referenced.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return bool `true` if the callback is currently referenced, otherwise `false`.
     */
    public static function isReferenced(string $callbackId): bool
    {
        return self::getDriver()->isReferenced($callbackId);
    }

    /**
     * Retrieve the event loop driver that is in scope.
     *
     * @return Driver
     */
    public static function getDriver(): Driver
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck, RedundantCondition */
        if (!isset(self::$driver)) {
            self::setDriver((new DriverFactory())->create());
        }

        return self::$driver;
    }

    /**
     * Whether the loop has a driver: true once one was created (by {@see self::getDriver()} or any loop call that
     * uses it), installed or set, and also while the loop swaps drivers (see {@see self::driverName()}). Never creates
     * one, so it is safe to call before {@see self::install()}.
     */
    public static function hasDriver(): bool
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        return isset(self::$driver);
    }

    /**
     * The name of the loop's driver: `poll` for a {@see PollDriver} (whatever its Io\Poll backend), `select` for a
     * {@see StreamSelectDriver}, the class name for any other {@see Driver}, and null when there is no usable driver:
     * none exists yet, or the loop is swapping drivers. Never creates a driver (unlike `Loop::getDriver()`), so it is
     * safe to call before {@see self::install()}. In a forked child it names the inherited driver until
     * {@see self::afterFork()} replaced it.
     *
     * A swap ({@see self::setDriver()}, {@see self::install()} replacing an idle driver, {@see self::afterFork()})
     * collects garbage behind an internal placeholder, before the new driver exists; only a destructor or an
     * unwinding fiber running in that window can ask, and it gets null.
     */
    public static function driverName(): ?string
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (!isset(self::$driver) || self::$driver === self::$placeholder) {
            return null;
        }

        return match (true) {
            self::$driver instanceof PollDriver => 'poll',
            self::$driver instanceof StreamSelectDriver => 'select',
            default => self::$driver::class,
        };
    }

    /**
     * Creates the driver named by $name and makes it the loop's driver: a process's own first setup.
     *
     * Call it before the loop has run anything. It refuses, changing nothing, while the loop is running (which
     * includes "something awaited from {main} and the loop has not drained") or while any callback is registered
     * (enabled or disabled, referenced or not), since swapping would silently drop that work. In a child forked from
     * a process that used the loop, call {@see self::afterFork()} instead; the refusal says so. When no driver exists
     * yet, none is created merely to inspect its state.
     *
     * @param string|null $name `auto`, `poll` or `select`; null reads `FLEDGE_EVENT_LOOP` (default `auto`).
     *
     * @throws LoopStateException When the loop is running or has callbacks registered (a \LogicException).
     * @throws UnknownDriverException When the name is not one of the accepted values (an \InvalidArgumentException;
     *     nothing is changed).
     * @throws UnsupportedFeatureException When `poll` is requested but unavailable (nothing is changed).
     */
    public static function install(?string $name = null): Driver
    {
        $resolved = DriverFactory::resolve($name);

        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (!isset(self::$driver)) {
            $driver = (new DriverFactory())->create($resolved);
            self::$driver = $driver;

            return $driver;
        }

        $current = self::$driver;

        if ($current->isRunning()) {
            throw new LoopStateException(\sprintf(
                'Cannot install the "%s" event loop driver while the event loop is running; install it before the loop starts.%s',
                $resolved,
                self::forkHint($current),
            ));
        }

        $registered = \count($current->getIdentifiers());

        if ($registered > 0) {
            throw new LoopStateException(\sprintf(
                'Cannot install the "%s" event loop driver: the active %s driver still has %d callback(s) registered.%s',
                $resolved,
                $current::class,
                $registered,
                self::forkHint($current),
            ));
        }

        unset($current);

        [$driver, , $unwindErrors] = self::replaceDriver($resolved);

        if ($unwindErrors !== []) {
            throw $unwindErrors[0]; // A fiber of the replaced driver failed while unwinding; the new driver is installed.
        }

        return $driver;
    }

    /**
     * Discards the driver inherited through `fork()` and installs a fresh one: the first call in a forked child.
     *
     * Call it in the child right after `pcntl_fork()` returned 0, from {main} (not inside a fiber or an event loop
     * callback, where it throws), before the child opens or closes any descriptor. A `pcntl_fork()` made inside an
     * event loop callback therefore leaves a child that can never call it: fork from {main} (see {@see self::fork()}). It works whatever state the inherited driver is in
     * (running because the parent awaited from {main}, callbacks registered). See the class documentation for what
     * the child gets. In this order:
     *
     * 1. The fiber check and the name validation; when either fails, nothing is changed.
     * 2. The inherited driver abandons itself with no kernel operation on the shared object: the poll driver releases
     *    its `Io\Poll\Context` (epoll: the child's copy of the descriptor is closed, the parent's registrations stay;
     *    kqueue: the descriptor was never inherited and its number may be reused, so the Context is parked until
     *    exit), every callback is dropped without deactivation, and the pcntl signal handlers the driver installed are
     *    reset to SIG_DFL. Dispositions set directly with `pcntl_signal()` are left untouched.
     * 3. A descriptor-free StreamSelectDriver placeholder is installed and garbage is collected, BEFORE any new
     *    descriptor is opened: an unreachable parent `Io\Poll\Context` waiting for the cycle collector would
     *    otherwise close, when collected, the kqueue number the new driver took. Fibers suspended in the parent
     *    unwind here, against the placeholder, all of them: a `finally` block or destructor that throws (or tries to
     *    suspend, which throws a FiberError in an unwinding fiber) does not stop the others, and its exception is
     *    kept for step 6.
     * 4. The new driver is created continuing the inherited callback id sequence, then installed. This always
     *    happens once step 2 started, whatever the unwinding fibers threw.
     * 5. If user code still references the inherited `Io\Poll\Context` (a value returned by the inherited driver's
     *    `getHandle()`), it throws a {@see LoopStateException} (a LogicException), with the new driver already
     *    installed: until that reference is dropped, closing an inherited stream would delete the parent's epoll
     *    registration. Holding the inherited driver object itself does not trigger this (it released its Context in
     *    step 2). On kqueue the Context is parked, so this never fires.
     * 6. If any fiber threw while unwinding, a {@see ForkUnwindException} (a RuntimeException) is thrown with the first
     *    one as previous and all of them in `getUnwindErrors()`, again with the new driver installed and usable. Those
     *    exceptions belong to the parent's abandoned work, not to the child, but they are reported rather than hidden;
     *    catching it and carrying on is safe. {@see self::fork()} logs them with `error_log()` and returns 0 instead.
     *
     * Only in a forked child: when the active driver was created by this very process (a process that never forked,
     * or a second call in the same child), there is no inherited loop, and afterFork() throws a LogicException without
     * touching the driver or its callbacks. That holds even when no callback is registered (use {@see self::install()}
     * to pick a driver in a process's own setup). In a child of a child, the driver inherited from the first child was
     * created by another process, so afterFork() works there again. Before any driver exists it simply creates one.
     *
     * The inherited driver must extend Fledge's own driver base (PollDriver, StreamSelectDriver). A third-party
     * {@see Driver} implementation cannot be abandoned without calling into it, which may make a kernel call per
     * callback on a descriptor shared with the parent, and its id scheme is unknown, so parent ids could alias the
     * child's: afterFork() refuses it with a LogicException, changing nothing. Make such a driver fork-safe yourself
     * and call {@see self::setDriver()} in the child.
     *
     * @param string|null $name `auto`, `poll` or `select`; null reads `FLEDGE_EVENT_LOOP` (default `auto`).
     *
     * @throws LoopStateException When called inside a fiber, when the active driver is not one of Fledge's own or was
     *     created by this process (all before anything changed), or when the inherited Context is still referenced
     *     (after the new driver was installed). A \LogicException.
     * @throws ForkUnwindException When fibers inherited from the parent threw while unwinding (after the new driver
     *     was installed). A \RuntimeException.
     * @throws UnknownDriverException When the name is not one of the accepted values (an \InvalidArgumentException;
     *     nothing is changed).
     * @throws UnsupportedFeatureException When `poll` is requested but unavailable (nothing is changed).
     */
    public static function afterFork(?string $name = null): Driver
    {
        [$driver, $unwindErrors] = self::startAfterFork($name);

        if ($unwindErrors !== []) {
            throw self::unwindFailure($unwindErrors);
        }

        return $driver;
    }

    /**
     * afterFork() without the final rethrow: the fresh driver and what the parent's fibers threw while unwinding.
     *
     * @return array{Driver, list<\Throwable>}
     *
     * @throws LoopStateException|UnknownDriverException|UnsupportedFeatureException As documented on afterFork().
     */
    private static function startAfterFork(?string $name): array
    {
        if (\Fiber::getCurrent() !== null) {
            throw new LoopStateException(
                'Fledge\\Loop::afterFork() must be called from {main}, not inside a fiber or an event loop callback; '
                .'call it in the child right after pcntl_fork() returned 0.',
            );
        }

        $resolved = DriverFactory::resolve($name);
        self::refuseForeignDriver('afterFork');
        self::refuseOwnDriver();

        [$driver, $released, $unwindErrors] = self::replaceDriver($resolved);

        if ($released?->get() !== null) {
            throw new LoopStateException(
                'Fledge\\Loop::afterFork() installed a fresh driver, but the inherited Io\\Poll\\Context is still '
                .'referenced (a value returned by the inherited driver\'s getHandle()), so the descriptor inherited from '
                .'the parent stays open in this process: closing an inherited stream would delete the parent\'s epoll '
                .'registration. Drop that reference before closing any inherited stream.',
                previous: $unwindErrors === [] ? null : self::unwindFailure($unwindErrors),
            );
        }

        return [$driver, $unwindErrors];
    }

    /**
     * @param non-empty-list<\Throwable> $errors
     */
    private static function unwindFailure(array $errors): ForkUnwindException
    {
        return new ForkUnwindException(
            \sprintf(
                'Fledge\\Loop::afterFork(): fibers inherited from the parent threw while unwinding in this child '
                .'(%s: %s; see the previous exception, which PHP chains to others thrown in the same collection). The '
                .'fresh event loop driver is installed and usable; these errors belong to the parent\'s abandoned work.',
                $errors[0]::class,
                $errors[0]->getMessage(),
            ),
            $errors,
        );
    }

    /**
     * @throws LoopStateException When the active driver was created by this process: there is no inherited loop.
     */
    private static function refuseOwnDriver(): void
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (!isset(self::$driver) || !self::$driver instanceof AbstractDriver) {
            return;
        }

        if (self::$driver->creatorPid() !== \getmypid()) {
            return;
        }

        throw new LoopStateException(\sprintf(
            'Fledge\\Loop::afterFork() is not in a forked child: the active %s was created by this process (%d), so '
            .'there is no inherited loop to discard and nothing to do (it has %d callback(s) registered, which are '
            .'kept). Call afterFork() once, in the child, right after pcntl_fork() returned 0; use '
            .'Fledge\\Loop::install() to choose a driver during a process\'s own setup.',
            self::$driver::class,
            \getmypid(),
            \count(self::$driver->getIdentifiers()),
        ));
    }

    /**
     * Forks the process and, in the child, calls {@see self::afterFork()}: returns the child's pid in the parent and
     * 0 in the child, which then runs on a fresh, empty loop chosen like `afterFork(null)` (`FLEDGE_EVENT_LOOP`).
     *
     * Call it from {main} at a quiet point: no fiber suspended mid-exchange on a connection, since such fibers unwind
     * in the child (see the class documentation). Never from an event loop callback or a fiber (it throws there): a
     * process that decides to fork while the loop runs suspends {main} on a {@see Suspension}, resumes it from the
     * callback, and forks in {main} once `suspend()` returned. The checks that can fail (fiber, pcntl, the driver
     * name, a third-party inherited driver) run in the parent before forking. Exceptions thrown by parent fibers
     * unwinding in the child are logged with `error_log()`, and the child carries on with its fresh loop. Should
     * `afterFork()` still fail in the child (an inherited Context still referenced), the child cannot report it to a
     * caller that cannot tell it from the parent: it logs the exception with `error_log()` and calls `exit(255)`,
     * which runs the shutdown functions inherited from the parent (a master's pid-file cleanup, for example), so such
     * functions should check `getmypid()`.
     *
     * @throws LoopStateException When called inside a fiber, or when the active driver is not one of Fledge's own (a
     *     \LogicException).
     * @throws UnsupportedFeatureException When the pcntl extension is missing.
     * @throws ForkFailedException When `pcntl_fork()` fails (no child was created). A \RuntimeException.
     * @throws UnknownDriverException When `FLEDGE_EVENT_LOOP` holds an invalid value (an \InvalidArgumentException;
     *     nothing is forked).
     */
    public static function fork(): int
    {
        if (\Fiber::getCurrent() !== null) {
            throw new LoopStateException(
                'Fledge\\Loop::fork() must be called from {main}, not inside a fiber or an event loop callback.',
            );
        }

        if (!\function_exists('pcntl_fork')) {
            throw new UnsupportedFeatureException('Fledge\\Loop::fork() requires the pcntl extension.');
        }

        $resolved = DriverFactory::resolve();
        self::refuseForeignDriver('fork');

        $pid = \pcntl_fork();

        if ($pid === -1) {
            throw new ForkFailedException(\sprintf(
                'Fledge\\Loop::fork(): pcntl_fork() failed: %s',
                \pcntl_strerror(\pcntl_get_last_error()),
            ));
        }

        if ($pid === 0) {
            try {
                [, $unwindErrors] = self::startAfterFork($resolved);
            } catch (\Throwable $exception) {
                \error_log(\sprintf('Fledge\\Loop::fork(): the child could not start a fresh event loop: %s', $exception));

                exit(255);
            }

            // The child's loop is ready; what the parent's fibers threw while unwinding is the parent's, not the
            // child's, so it is logged instead of ending the child.
            foreach ($unwindErrors as $error) {
                \error_log(\sprintf('Fledge\\Loop::fork(): a fiber inherited from the parent threw while unwinding in the child: %s', $error));
            }
        }

        return $pid;
    }

    /**
     * Whether the current fiber is the active driver's own loop fiber, which runs the driver's dispatch (and with it
     * hooked I/O, e.g. fiberio's idle usleep()) and must never suspend through {@see self::getSuspension()}. False in
     * {main}, in every other fiber, when no driver exists yet, and for a third-party driver.
     */
    public static function inLoopFiber(): bool
    {
        $fiber = \Fiber::getCurrent();

        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if ($fiber === null || !isset(self::$driver)) {
            return false;
        }

        $driver = self::$driver;

        return $driver instanceof AbstractDriver && $driver->isLoopFiber($fiber);
    }

    /**
     * Abandons the current driver (when it is one of Fledge's own), installs a descriptor-free placeholder and
     * collects garbage, then creates and installs the new driver continuing the old id sequence. The new driver is
     * always created and installed: what unwinding fibers throw during the collection is caught and returned.
     *
     * @param 'poll'|'select' $resolved
     *
     * @return array{Driver, \WeakReference<object>|null, list<\Throwable>} The new driver, the released kernel handle to
     *     check, and the exceptions thrown by fibers that unwound during the collection.
     */
    private static function replaceDriver(string $resolved): array
    {
        $nextId = null;
        $released = null;

        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (isset(self::$driver) && self::$driver instanceof AbstractDriver) {
            $nextId = self::$driver->nextCallbackId();
            $released = self::$driver->abandonAfterFork();
        }

        // Every reference the facade holds goes away here; the placeholder owns no descriptor.
        $previousPlaceholder = self::$placeholder;
        self::$driver = self::$placeholder = new StreamSelectDriver();

        try {
            $unwindErrors = self::collectGarbage();

            $driver = (new DriverFactory())->create($resolved);

            if ($nextId !== null && $driver instanceof AbstractDriver) {
                $driver->continueCallbackIds($nextId);
            }

            self::$driver = $driver;
        } finally {
            self::$placeholder = $previousPlaceholder;
        }

        return [$driver, $released, $unwindErrors];
    }

    /**
     * Runs the cycle collector until two passes in a row complete. Two passes: the first frees the abandoned driver's
     * old fibers (cycles through their own stacks), only then is the driver itself unreachable. A fiber destroyed
     * here unwinds, and its `finally` blocks or destructors may throw (or try to suspend, which throws a FiberError):
     * such an exception ends that gc_collect_cycles() call, so it is caught and the collection is run again, which
     * goes on with the remaining garbage. Every fiber therefore unwinds here, not at a random later point.
     *
     * @return list<\Throwable>
     */
    private static function collectGarbage(): array
    {
        $errors = [];
        $cleanPasses = 0;

        // Each failed call is one throwing unwind; the bound only guards against garbage that never settles.
        for ($calls = 0; $cleanPasses < 2 && $calls < 10_000; $calls++) {
            try {
                \gc_collect_cycles();
                $cleanPasses++;
            // @phpstan-ignore catch.neverThrown (destructors and unwinding fibers' finally blocks throw out of the GC)
            } catch (\Throwable $error) {
                $errors[] = $error;
                $cleanPasses = 0;
            }
        }

        return $errors;
    }

    /**
     * @throws LoopStateException When the active driver does not extend Fledge's driver base.
     */
    private static function refuseForeignDriver(string $method): void
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (isset(self::$driver) && !self::$driver instanceof AbstractDriver) {
            throw new LoopStateException(\sprintf(
                'Fledge\\Loop::%s() cannot abandon the active %s: only Fledge\'s own drivers (PollDriver, '
                .'StreamSelectDriver) can be discarded in a forked child without calling into them. Make that driver '
                .'fork-safe yourself and call Fledge\\Loop::setDriver() in the child.',
                $method,
                self::$driver::class,
            ));
        }
    }

    private static function forkHint(Driver $current): string
    {
        if ($current instanceof AbstractDriver && $current->creatorPid() !== \getmypid()) {
            return \sprintf(
                ' The active driver was created in process %d and inherited through fork(): call Fledge\\Loop::afterFork() in the child instead.',
                $current->creatorPid(),
            );
        }

        return ' In a child forked from a process that used the event loop, call Fledge\\Loop::afterFork() instead.';
    }

    /**
     * Returns an object used to suspend and resume execution of the current fiber or {main}.
     *
     * Calls from the same fiber will return the same suspension object.
     *
     * @return Suspension
     */
    public static function getSuspension(): Suspension
    {
        return self::getDriver()->getSuspension();
    }

    /**
     * Run the event loop.
     *
     * This function may only be called from {main}, that is, not within a fiber.
     *
     * Libraries should use the {@link Suspension} API instead of calling this method.
     *
     * This method will not return until the event loop does not contain any pending, referenced callbacks anymore.
     */
    public static function run(): void
    {
        self::getDriver()->run();
    }

    /**
     * Disable construction as this is a static class.
     *
     * @psalm-suppress UnusedConstructor
     */
    private function __construct()
    {
        // intentionally left blank
    }
}
