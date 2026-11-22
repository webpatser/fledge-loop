<?php

declare(strict_types=1);

// Derived from revolt/event-loop v1.1.0, src/EventLoop/Internal/AbstractDriver.php.
// MIT License; copyright notices in LICENSE.

namespace Fledge\Loop\Internal;

use Fledge\Loop\CallbackType;
use Fledge\Loop\Driver;
use Fledge\Loop\FiberLocal;
use Fledge\Loop\InvalidCallbackError;
use Fledge\Loop\Suspension;
use Fledge\Loop\UncaughtThrowable;

/**
 * Event loop driver which implements all basic operations to allow interoperability.
 *
 * Callbacks (enabled or new callbacks) MUST immediately be marked as enabled, but only be activated (i.e. callbacks can
 * be called) right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
 *
 * @internal
 */
abstract class AbstractDriver implements Driver
{
    /** @var string Next callback identifier. */
    private string $nextId = "a";

    private \Fiber $fiber;

    private \Fiber $callbackFiber;
    private \Closure $errorCallback;

    /** @var array<string, DriverCallback> */
    private array $callbacks = [];

    /**
     * How many of $callbacks are enabled and referenced; the loop runs while it is above zero. Kept up to date by
     * every method that registers, cancels, enables, disables, references or unreferences a callback, so the check
     * that runs twice per tick is O(1). With assertions on, every check compares it with a full scan.
     */
    private int $enabledReferenced = 0;

    /** @var array<string, DriverCallback> */
    private array $enableQueue = [];

    /** @var array<string, DriverCallback> */
    private array $enableDeferQueue = [];

    /** @var null|\Closure(\Throwable):void */
    private ?\Closure $errorHandler = null;

    /** @var null|\Closure():mixed */
    private ?\Closure $interrupt = null;

    private readonly \Closure $interruptCallback;
    private readonly \Closure $queueCallback;

    /** @var \Closure():(null|\Closure(): mixed) */
    private readonly \Closure $runCallback;

    private readonly \stdClass $internalSuspensionMarker;

    /**
     * Microtask FIFO: closures and their arguments at the same positions, from $microtaskHead (next to run) to
     * $microtaskTail (next free). Both arrays are reset whenever the queue runs empty, so positions restart at 0.
     *
     * @var array<int, \Closure>
     */
    private array $microtasks = [];

    /** @var array<int, array<array-key, mixed>> Arguments of the microtask at the same position, if it has any. */
    private array $microtaskArgs = [];

    private int $microtaskHead = 0;

    private int $microtaskTail = 0;

    /** @var array<int, DriverCallback> Callback FIFO from $callbackHead to $callbackTail, reset when it runs empty. */
    private array $callbackQueue = [];

    private int $callbackHead = 0;

    private int $callbackTail = 0;

    private bool $idle = false;
    private bool $stopped = false;

    /** @var \WeakMap<object, \WeakReference<DriverSuspension>> */
    private \WeakMap $suspensions;

    /** Process that created the driver; a forked child inherits the object but not the ownership. */
    protected readonly int $creatorPid;

    public function __construct()
    {
        $this->creatorPid = \getmypid()
            ?: throw new \RuntimeException('getmypid() failed: cannot record the process that owns this event loop driver');
        $this->suspensions = new \WeakMap();

        $this->internalSuspensionMarker = new \stdClass();

        $this->createLoopFiber();
        $this->createCallbackFiber();
        $this->createErrorCallback();

        /** @psalm-suppress InvalidArgument */
        $this->interruptCallback = $this->setInterrupt(...);
        $this->queueCallback = $this->queue(...);
        $this->runCallback = function (): ?\Closure {
            do {
                if ($this->fiber->isTerminated()) {
                    $this->createLoopFiber();
                }

                $result = $this->fiber->isStarted() ? $this->fiber->resume() : $this->fiber->start();
                if ($result) { // Null indicates the loop fiber terminated without suspending.
                    return $result;
                }
            } while (\gc_collect_cycles() && !$this->stopped);

            return null;
        };
    }

    #[\Override]
    public function run(): void
    {
        if ($this->fiber->isRunning()) {
            throw new \Error("The event loop is already running");
        }

        if (\Fiber::getCurrent()) {
            throw new \Error(\sprintf("Can't call %s() within a fiber (i.e., outside of {main})", __METHOD__));
        }

        $lambda = ($this->runCallback)();

        if ($lambda) {
            $lambda();

            throw new \Error(
                'Interrupt from event loop must throw an exception: ' . ClosureHelper::getDescription($lambda)
            );
        }
    }

    #[\Override]
    public function stop(): void
    {
        $this->stopped = true;
    }

    #[\Override]
    public function isRunning(): bool
    {
        return $this->fiber->isRunning() || $this->fiber->isSuspended();
    }

    #[\Override]
    public function queue(\Closure $closure, mixed ...$args): void
    {
        $this->microtasks[$this->microtaskTail] = $closure;

        if ($args) {
            $this->microtaskArgs[$this->microtaskTail] = $args;
        }

        ++$this->microtaskTail;
    }

    #[\Override]
    public function defer(\Closure $closure): string
    {
        $deferCallback = new DeferCallback($this->callbackId(), $closure);

        $this->callbacks[$deferCallback->id] = $deferCallback;
        $this->enableDeferQueue[$deferCallback->id] = $deferCallback;
        ++$this->enabledReferenced;

        return $deferCallback->id;
    }

    #[\Override]
    public function delay(float $delay, \Closure $closure): string
    {
        // NAN fails every comparison: rejected here, it would corrupt the timer heap's order.
        if (!($delay >= 0)) {
            throw new \Error(\is_nan($delay) ? "Delay must be a number, NAN given" : "Delay must be greater than or equal to zero");
        }

        $timerCallback = new TimerCallback($this->callbackId(), $delay, $closure, $this->now() + $delay);

        $this->callbacks[$timerCallback->id] = $timerCallback;
        $this->enableQueue[$timerCallback->id] = $timerCallback;
        ++$this->enabledReferenced;

        return $timerCallback->id;
    }

    #[\Override]
    public function repeat(float $interval, \Closure $closure): string
    {
        if (!($interval >= 0)) {
            throw new \Error(\is_nan($interval) ? "Interval must be a number, NAN given" : "Interval must be greater than or equal to zero");
        }

        $timerCallback = new TimerCallback($this->callbackId(), $interval, $closure, $this->now() + $interval, true);

        $this->callbacks[$timerCallback->id] = $timerCallback;
        $this->enableQueue[$timerCallback->id] = $timerCallback;
        ++$this->enabledReferenced;

        return $timerCallback->id;
    }

    #[\Override]
    public function onReadable(mixed $stream, \Closure $closure): string
    {
        $streamCallback = new StreamReadableCallback($this->callbackId(), $closure, $stream);

        $this->callbacks[$streamCallback->id] = $streamCallback;
        $this->enableQueue[$streamCallback->id] = $streamCallback;
        ++$this->enabledReferenced;

        return $streamCallback->id;
    }

    #[\Override]
    public function onWritable($stream, \Closure $closure): string
    {
        $streamCallback = new StreamWritableCallback($this->callbackId(), $closure, $stream);

        $this->callbacks[$streamCallback->id] = $streamCallback;
        $this->enableQueue[$streamCallback->id] = $streamCallback;
        ++$this->enabledReferenced;

        return $streamCallback->id;
    }

    #[\Override]
    public function onSignal(int $signal, \Closure $closure): string
    {
        $signalCallback = new SignalCallback($this->callbackId(), $closure, $signal);

        $this->callbacks[$signalCallback->id] = $signalCallback;
        $this->enableQueue[$signalCallback->id] = $signalCallback;
        ++$this->enabledReferenced;

        return $signalCallback->id;
    }

    #[\Override]
    public function enable(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            throw InvalidCallbackError::invalidIdentifier($callbackId);
        }

        $callback = $this->callbacks[$callbackId];

        if ($callback->enabled) {
            return $callbackId; // Callback already enabled.
        }

        $callback->enabled = true;

        if ($callback->referenced) {
            ++$this->enabledReferenced;
        }

        if ($callback instanceof DeferCallback) {
            $this->enableDeferQueue[$callback->id] = $callback;
        } elseif ($callback instanceof TimerCallback) {
            $callback->expiration = $this->now() + $callback->interval;
            $this->enableQueue[$callback->id] = $callback;
        } else {
            $this->enableQueue[$callback->id] = $callback;
        }

        return $callbackId;
    }

    #[\Override]
    public function cancel(string $callbackId): void
    {
        $this->disable($callbackId);
        unset($this->callbacks[$callbackId]);
    }

    #[\Override]
    public function disable(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            return $callbackId;
        }

        $callback = $this->callbacks[$callbackId];

        if (!$callback->enabled) {
            return $callbackId; // Callback already disabled.
        }

        $callback->enabled = false;
        $callback->invokable = false;

        if ($callback->referenced) {
            --$this->enabledReferenced;
        }

        $id = $callback->id;

        if ($callback instanceof DeferCallback) {
            // Callback was only queued to be enabled.
            unset($this->enableDeferQueue[$id]);
        } elseif (isset($this->enableQueue[$id])) {
            // Callback was only queued to be enabled.
            unset($this->enableQueue[$id]);
        } else {
            $this->deactivate($callback);
        }

        return $callbackId;
    }

    #[\Override]
    public function reference(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            throw InvalidCallbackError::invalidIdentifier($callbackId);
        }

        $callback = $this->callbacks[$callbackId];

        if (!$callback->referenced) {
            $callback->referenced = true;

            if ($callback->enabled) {
                ++$this->enabledReferenced;
            }
        }

        return $callbackId;
    }

    #[\Override]
    public function unreference(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            return $callbackId;
        }

        $callback = $this->callbacks[$callbackId];

        if ($callback->referenced) {
            $callback->referenced = false;

            if ($callback->enabled) {
                --$this->enabledReferenced;
            }
        }

        return $callbackId;
    }

    #[\Override]
    public function getSuspension(): Suspension
    {
        $fiber = \Fiber::getCurrent();

        // User callbacks are always executed outside the event loop fiber, so this should always be false.
        \assert($fiber !== $this->fiber);

        // Use queue closure in case of {main}, which can be unset by DriverSuspension after an uncaught exception.
        $key = $fiber ?? $this->queueCallback;

        $suspension = ($this->suspensions[$key] ?? null)?->get();
        if ($suspension) {
            return $suspension;
        }

        $suspension = new DriverSuspension(
            $this->runCallback,
            $this->queueCallback,
            $this->interruptCallback,
            $this->suspensions,
        );

        $this->suspensions[$key] = \WeakReference::create($suspension);

        return $suspension;
    }

    #[\Override]
    public function setErrorHandler(?\Closure $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    #[\Override]
    public function getErrorHandler(): ?\Closure
    {
        return $this->errorHandler;
    }

    #[\Override]
    public function __debugInfo(): array
    {
        // @codeCoverageIgnoreStart
        return \array_map(fn (DriverCallback $callback) => [
            'type' => $callback->getType(),
            'enabled' => $callback->enabled,
            'referenced' => $callback->referenced,
        ], $this->callbacks);
        // @codeCoverageIgnoreEnd
    }

    #[\Override]
    public function getIdentifiers(): array
    {
        return \array_keys($this->callbacks);
    }

    #[\Override]
    public function getType(string $callbackId): CallbackType
    {
        $callback = $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);

        return $callback->getType();
    }

    #[\Override]
    public function isEnabled(string $callbackId): bool
    {
        $callback = $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);

        return $callback->enabled;
    }

    #[\Override]
    public function isReferenced(string $callbackId): bool
    {
        $callback = $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);

        return $callback->referenced;
    }

    /**
     * Activates (enables) all the given callbacks.
     *
     * @param array<string, DriverCallback> $callbacks
     */
    abstract protected function activate(array $callbacks): void;

    /**
     * Dispatches any pending read/write, timer, and signal events.
     */
    abstract protected function dispatch(bool $blocking): void;

    /**
     * Deactivates (disables) the given callback.
     */
    abstract protected function deactivate(DriverCallback $callback): void;

    final protected function enqueueCallback(DriverCallback $callback): void
    {
        $this->callbackQueue[$this->callbackTail++] = $callback;
        $this->idle = false;
    }

    /**
     * Invokes the error handler with the given exception.
     *
     * @param \Throwable $exception The exception thrown from an event callback.
     */
    final protected function error(\Closure $closure, \Throwable $exception): void
    {
        if ($this->errorHandler === null) {
            // Explicitly override the previous interrupt if it exists in this case, hiding the exception is worse
            $this->interrupt = static fn () => $exception instanceof UncaughtThrowable
                ? throw $exception
                : throw UncaughtThrowable::throwingCallback($closure, $exception);
            return;
        }

        $fiber = new \Fiber($this->errorCallback);

        /** @noinspection PhpUnhandledExceptionInspection */
        $fiber->start($this->errorHandler, $exception);
    }

    /**
     * Returns the current event loop time in second increments.
     *
     * Note this value does not necessarily correlate to wall-clock time, rather the value returned is meant to be used
     * in relative comparisons to prior values returned by this method (intervals, expiration calculations, etc.).
     */
    abstract protected function now(): float;

    private function invokeMicrotasks(\Fiber $fiber): void
    {
        while ($this->microtaskHead !== $this->microtaskTail) {
            $position = $this->microtaskHead++;
            $callback = $this->microtasks[$position];
            $args = $this->microtaskArgs[$position] ?? [];
            unset($this->microtasks[$position], $this->microtaskArgs[$position]);

            if ($this->microtaskHead === $this->microtaskTail) {
                $this->microtasks = [];
                $this->microtaskArgs = [];
                $this->microtaskHead = $this->microtaskTail = 0;
            }

            try {
                // Clear $args to allow garbage collection
                $callback(...$args, ...($args = []));
            } catch (\Throwable $exception) {
                $this->error($callback, $exception);
            } finally {
                FiberLocal::clear();
            }

            unset($callback, $args);

            if ($fiber !== $this->callbackFiber) {
                return;
            }

            if ($this->interrupt) {
                /** @noinspection PhpUnhandledExceptionInspection */
                \Fiber::suspend($this->internalSuspensionMarker);
            }
        }
    }

    /**
     * Assertion helper: the counter must equal what a scan of all callbacks finds.
     *
     * @throws \AssertionError When the bookkeeping drifted.
     */
    private function enabledReferencedMatchesScan(): bool
    {
        // A signal handler run asynchronously during the scan could register or change a callback, and the scan would
        // compare a half-old view with the current count. Async dispatch is paused for the scan and the comparison; a
        // signal that arrived meanwhile is dispatched right after them.
        $async = \function_exists('pcntl_async_signals') && \pcntl_async_signals(false);
        $scanned = 0;

        try {
            foreach ($this->callbacks as $callback) {
                if ($callback->enabled && $callback->referenced) {
                    ++$scanned;
                }
            }

            $counted = $this->enabledReferenced;
        } finally {
            if ($async) {
                \pcntl_async_signals(true);
                \pcntl_signal_dispatch();
            }
        }

        if ($scanned !== $counted) {
            throw new \AssertionError(\sprintf(
                'Enabled and referenced callback count is %d, a scan finds %d',
                $counted,
                $scanned,
            ));
        }

        return true;
    }

    /**
     * Executes a single tick of the event loop.
     */
    private function tick(bool $previousIdle): void
    {
        // Each queue is taken and emptied before anything is called: a signal handler run asynchronously
        // (pcntl_async_signals(true)) at any call in between may enable or defer a callback, which must land in the
        // next tick's queue instead of being dropped by a reset after the loop below.
        $enableQueue = $this->enableQueue;
        $this->enableQueue = [];

        // Such a handler (or user code run inside activate() or deactivate()) may also change a taken callback: disable
        // or cancel it (disable() no longer finds it queued and deactivates it, possibly before activate() reached it),
        // or disable and enable it again (enable() puts it in the live queue). The live queue is authoritative: whatever
        // this tick activated for a taken callback is undone when the callback is disabled now or queued again, so a
        // callback is active at most once and a re-enabled one is activated by the next tick only. deactivate() is
        // idempotent, which makes the undo safe whichever of these happened and in what order.
        $this->activate($enableQueue);

        foreach ($enableQueue as $id => $callback) {
            if ($callback->enabled && !isset($this->enableQueue[$id])) {
                $callback->invokable = true;
            } else {
                $this->deactivate($callback);
            }
        }

        $enableDeferQueue = $this->enableDeferQueue;
        $this->enableDeferQueue = [];

        foreach ($enableDeferQueue as $callback) {
            if ($callback->enabled) {
                $callback->invokable = true;
                $this->enqueueCallback($callback);
            }
        }

        // The loop fiber can stay suspended inside this tick (in invokeCallbacks() below); the taken queues must not
        // keep their callbacks alive there.
        unset($enableQueue, $enableDeferQueue, $callback);

        $blocking = $previousIdle
            && !$this->stopped
            && $this->enabledReferenced !== 0;

        if ($blocking) {
            $this->invokeCallbacks();

            // The callbacks just run may have stopped the loop, cancelled the last referenced callback or queued
            // work; the decision above predates them.
            $blocking = $this->mayBlock();
        }

        $this->dispatch($blocking);
    }

    /**
     * Whether a wait may still block, judged on the state as it is now: false once the loop was stopped, when no
     * enabled and referenced callback is left, or when work is waiting for the next tick (a microtask, a queued
     * callback, a callback enabled or deferred since the tick began).
     *
     * The decision to block is made at the start of a tick, before user code runs in it: the callbacks a blocking tick
     * runs first, and in a driver's dispatch() the signal handlers run by pcntl_signal_dispatch() and the destructors
     * or stream wrapper code run when watcher changes release a stream. Anything of that may call stop(), cancel the
     * last referenced callback, or register a timer due before the wait's timeout. tick() calls this after its
     * callbacks, and a driver's dispatch() calls it again after its own user code, right before it computes the wait
     * timeout, so none of those waits blocks on a decision that is out of date. Only a blocking wait asks, so a busy
     * loop pays nothing for it.
     *
     * Two kinds of user code can still run between this check and the kernel wait: a signal handler run
     * asynchronously (pcntl_async_signals(true)), and a user-space wrapper's stream_cast(), which stream_select() calls
     * inside the wait call. PHP offers no atomic way to close either window, so the drivers bound it instead: every
     * wait is capped at {@see IdleSleep::SIGNAL_WAIT_CAP} while async dispatch is on, and every blocking
     * stream_select() is capped there always.
     */
    final protected function mayBlock(): bool
    {
        return !$this->stopped
            && $this->enabledReferenced !== 0
            && $this->enableQueue === []
            && $this->enableDeferQueue === []
            && $this->microtaskHead === $this->microtaskTail
            && $this->callbackHead === $this->callbackTail;
    }

    private function invokeCallbacks(): void
    {
        while ($this->microtaskHead !== $this->microtaskTail || $this->callbackHead !== $this->callbackTail) {
            /** @noinspection PhpUnhandledExceptionInspection */
            if ($this->callbackFiber->isSuspended()) {
                $yielded = $this->callbackFiber->resume();
            } else {
                if ($this->callbackFiber->isTerminated()) {
                    $this->createCallbackFiber();
                }
                $yielded = $this->callbackFiber->start();
            }

            if ($yielded !== $this->internalSuspensionMarker) {
                $this->createCallbackFiber();
            }

            if ($this->interrupt) {
                $this->invokeInterrupt();
            }
        }
    }

    /**
     * @param \Closure():mixed $interrupt
     */
    private function setInterrupt(\Closure $interrupt): void
    {
        \assert($this->interrupt === null);

        $this->interrupt = $interrupt;
    }

    private function invokeInterrupt(): void
    {
        \assert($this->interrupt !== null);

        $interrupt = $this->interrupt;
        $this->interrupt = null;

        /** @noinspection PhpUnhandledExceptionInspection */
        \Fiber::suspend($interrupt);
    }

    private function createLoopFiber(): void
    {
        $this->fiber = new \Fiber(function (): void {
            $this->stopped = false;

            // Invoke microtasks if we have some
            $this->invokeCallbacks();

            /** @psalm-suppress RedundantCondition $this->stopped may be changed by $this->invokeCallbacks(). */
            while (!$this->stopped) {
                if ($this->interrupt) {
                    $this->invokeInterrupt();
                }

                \assert($this->enabledReferencedMatchesScan());

                if ($this->enabledReferenced === 0) {
                    return;
                }

                $previousIdle = $this->idle;
                $this->idle = true;

                $this->tick($previousIdle);
                $this->invokeCallbacks();
            }
        });
    }

    private function createCallbackFiber(): void
    {
        $this->callbackFiber = new \Fiber(function (): void {
            /** @var \Fiber $fiber */
            $fiber = \Fiber::getCurrent();

            do {
                $this->invokeMicrotasks($fiber);

                while ($fiber === $this->callbackFiber && $this->callbackHead !== $this->callbackTail) {
                    $position = $this->callbackHead++;
                    $callback = $this->callbackQueue[$position];
                    unset($this->callbackQueue[$position]);

                    if ($this->callbackHead === $this->callbackTail) {
                        $this->callbackQueue = [];
                        $this->callbackHead = $this->callbackTail = 0;
                    }

                    if (!isset($this->callbacks[$callback->id]) || !$callback->invokable) {
                        unset($callback);

                        continue;
                    }

                    if ($callback instanceof DeferCallback) {
                        $this->cancel($callback->id);
                    } elseif ($callback instanceof TimerCallback) {
                        if (!$callback->repeat) {
                            $this->cancel($callback->id);
                        } else {
                            // Disable and re-enable, so it's not executed repeatedly in the same tick
                            // See https://github.com/amphp/amp/issues/131
                            $this->disable($callback->id);
                            $this->enable($callback->id);
                        }
                    }

                    try {
                        $result = match (true) {
                            $callback instanceof StreamCallback => ($callback->closure)(
                                $callback->id,
                                $callback->stream
                            ),
                            $callback instanceof SignalCallback => ($callback->closure)(
                                $callback->id,
                                $callback->signal
                            ),
                            default => ($callback->closure)($callback->id),
                        };

                        if ($result !== null) {
                            throw InvalidCallbackError::nonNullReturn($callback->id, $callback->closure);
                        }
                    } catch (\Throwable $exception) {
                        $this->error($callback->closure, $exception);
                    } finally {
                        FiberLocal::clear();
                    }

                    unset($callback);

                    if ($fiber !== $this->callbackFiber) {
                        return;
                    }

                    if ($this->interrupt) {
                        /** @noinspection PhpUnhandledExceptionInspection */
                        \Fiber::suspend($this->internalSuspensionMarker);
                    }

                    if ($this->microtaskHead !== $this->microtaskTail) {
                        $this->invokeMicrotasks($fiber);
                    }
                }

                if ($fiber !== $this->callbackFiber) {
                    return;
                }

                /** @noinspection PhpUnhandledExceptionInspection */
                \Fiber::suspend($this->internalSuspensionMarker);
            } while (true);
        });
    }

    private function createErrorCallback(): void
    {
        $this->errorCallback = function (\Closure $errorHandler, \Throwable $exception): void {
            try {
                $errorHandler($exception);
            } catch (\Throwable $exception) {
                $this->interrupt = static fn () => $exception instanceof UncaughtThrowable
                    ? throw $exception
                    : throw UncaughtThrowable::throwingErrorHandler($errorHandler, $exception);
            }
        };
    }

    private function callbackId(): string
    {
        $callbackId = $this->nextId;

        $this->nextId = \str_increment($this->nextId);

        return $callbackId;
    }

    /**
     * The process that created this driver.
     *
     * @internal For {@see \Fledge\Loop}; not part of the public API.
     */
    final public function creatorPid(): int
    {
        return $this->creatorPid;
    }

    /**
     * Whether $fiber is this driver's own loop fiber, which runs the driver's dispatch and must never suspend.
     *
     * @internal For {@see \Fledge\Loop::inLoopFiber()}; not part of the public API.
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    final public function isLoopFiber(\Fiber $fiber): bool
    {
        return $fiber === $this->fiber;
    }

    /**
     * The identifier the next registered callback will get.
     *
     * @internal For {@see \Fledge\Loop::afterFork()}; not part of the public API.
     */
    final public function nextCallbackId(): string
    {
        return $this->nextId;
    }

    /**
     * Moves the identifier sequence forward to $nextId, so identifiers another driver handed out (the one inherited
     * through fork()) are unknown here instead of naming this driver's callbacks. Never moves it backwards.
     *
     * @internal For {@see \Fledge\Loop::afterFork()}; not part of the public API.
     *
     * @throws \InvalidArgumentException When $nextId is not an identifier of this sequence.
     */
    final public function continueCallbackIds(string $nextId): void
    {
        if (\preg_match('/^[a-z]+$/D', $nextId) !== 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an event loop callback identifier', $nextId));
        }

        $length = \strlen($nextId);
        $current = \strlen($this->nextId);

        if ($length > $current || ($length === $current && \strcmp($nextId, $this->nextId) > 0)) {
            $this->nextId = $nextId;
        }
    }

    /**
     * Abandons the driver without any kernel operation, for a process that must stop using it: a forked child that
     * inherited it, or {@see \Fledge\Loop::install()} replacing an idle driver.
     *
     * Every callback is dropped without being deactivated (no syscall per callback), queued work is discarded, and
     * the loop and callback fibers are replaced by fresh ones. Once its loop has run, a driver keeps those fibers
     * suspended, and PHP never collects an object held by its own suspended fiber; replacing them makes the driver
     * collectable and makes {@see self::isRunning()} report false. The old fibers unwind at that point; neither has a
     * `finally` block on its suspended path. Subclasses release their kernel state first and then call this method.
     *
     * The driver must not be used afterwards.
     *
     * @internal For {@see \Fledge\Loop}; not part of the public API.
     *
     * @return \WeakReference<object>|null A kernel handle the driver released, to check that nothing else keeps it
     *     alive; null when there is none to check.
     */
    public function abandonAfterFork(): ?\WeakReference
    {
        $this->callbacks = [];
        $this->enabledReferenced = 0;
        $this->enableQueue = [];
        $this->enableDeferQueue = [];

        $this->microtasks = [];
        $this->microtaskArgs = [];
        $this->microtaskHead = $this->microtaskTail = 0;
        $this->callbackQueue = [];
        $this->callbackHead = $this->callbackTail = 0;

        $this->interrupt = null;
        $this->idle = false;
        $this->stopped = false;

        $this->createLoopFiber();
        $this->createCallbackFiber();

        return null;
    }

    final public function __serialize(): never
    {
        throw new \Error(__CLASS__ . ' does not support serialization');
    }

    /**
     * @psalm-suppress UnusedParam $data param required.
     */
    final public function __unserialize(array $data): never
    {
        throw new \Error(__CLASS__ . ' does not support deserialization');
    }
}
