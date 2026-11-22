<?php

declare(strict_types=1);

// Derived from revolt/event-loop v1.1.0, src/EventLoop/Driver/StreamSelectDriver.php.
// MIT License; copyright notices in LICENSE.

/** @noinspection PhpComposerExtensionStubsInspection */

namespace Fledge\Loop\Driver;

use Fledge\Loop\Internal\AbstractDriver;
use Fledge\Loop\Internal\DriverCallback;
use Fledge\Loop\Internal\IdleSleep;
use Fledge\Loop\Internal\SignalCallback;
use Fledge\Loop\Internal\StreamReadableCallback;
use Fledge\Loop\Internal\StreamWritableCallback;
use Fledge\Loop\Internal\TimerCallback;
use Fledge\Loop\Internal\TimerQueue;
use Fledge\Loop\UnsupportedFeatureException;

final class StreamSelectDriver extends AbstractDriver
{
    /** @var array<int, resource> */
    private array $readStreams = [];

    /** @var array<int, array<string, StreamReadableCallback>> */
    private array $readCallbacks = [];

    /** @var array<int, resource> */
    private array $writeStreams = [];

    /** @var array<int, array<string, StreamWritableCallback>> */
    private array $writeCallbacks = [];

    private TimerQueue $timerQueue;

    /** @var array<int, array<string, SignalCallback>> */
    private array $signalCallbacks = [];

    /** @var \SplQueue<int> */
    private readonly \SplQueue $signalQueue;

    private bool $signalHandling;

    private readonly \Closure $streamSelectErrorHandler;

    private bool $streamSelectIgnoreResult = false;

    public function __construct()
    {
        parent::__construct();

        $this->signalQueue = new \SplQueue();
        $this->timerQueue = new TimerQueue();
        $this->signalHandling = \extension_loaded("pcntl")
            && \function_exists('pcntl_signal_dispatch')
            && \function_exists('pcntl_signal');

        $this->streamSelectErrorHandler = function (int $errno, string $message): void {
            // Casing changed in PHP 8 from 'unable' to 'Unable'
            if (\stripos($message, "stream_select(): unable to select [4]: ") === 0) { // EINTR
                $this->streamSelectIgnoreResult = true;

                return;
            }

            if (\str_contains($message, 'FD_SETSIZE')) {
                $message = \str_replace(["\r\n", "\n", "\r"], " ", $message);
                $pattern = '(stream_select\(\): You MUST recompile PHP with a larger value of FD_SETSIZE. It is set to (\d+), but you have descriptors numbered at least as high as (\d+)\.)';

                if (\preg_match($pattern, $message, $match)) {
                    $message = 'You have reached the limits of stream_select(). It has a FD_SETSIZE of ' . $match[1]
                        . ', but you have file descriptors numbered at least as high as ' . $match[2] . '. '
                        . "Use the poll driver (FLEDGE_EVENT_LOOP=poll, or unset for the default) to support a higher "
                        . "number of concurrent file descriptors. If a large number of open file descriptors is "
                        . "unexpected, you might be leaking file descriptors that aren't closed correctly.";
                }
            }

            throw new \Exception($message, $errno);
        };
    }

    public function __destruct()
    {
        foreach ($this->signalCallbacks as $signalCallbacks) {
            foreach ($signalCallbacks as $signalCallback) {
                $this->deactivate($signalCallback);
            }
        }
    }

    /**
     * Drops every stream, timer and signal callback without a kernel operation and resets to SIG_DFL the pcntl
     * signal handlers this driver installed. The driver owns no descriptor, so there is nothing else to release.
     *
     * @internal For {@see \Fledge\Loop}; not part of the public API.
     */
    #[\Override]
    public function abandonAfterFork(): ?\WeakReference
    {
        foreach (\array_keys($this->signalCallbacks) as $signal) {
            \set_error_handler(static fn () => true);
            try {
                \pcntl_signal($signal, \SIG_DFL);
            } finally {
                \restore_error_handler();
            }
        }

        $this->signalCallbacks = [];
        $this->readStreams = [];
        $this->readCallbacks = [];
        $this->writeStreams = [];
        $this->writeCallbacks = [];
        $this->timerQueue = new TimerQueue();

        while (!$this->signalQueue->isEmpty()) {
            $this->signalQueue->dequeue();
        }

        return parent::abandonAfterFork();
    }

    /**
     * @throws UnsupportedFeatureException If the pcntl extension is not available.
     */
    #[\Override]
    public function onSignal(int $signal, \Closure $closure): string
    {
        if (!$this->signalHandling) {
            throw new UnsupportedFeatureException("Signal handling requires the pcntl extension");
        }

        return parent::onSignal($signal, $closure);
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

    /**
     * @throws \Throwable
     */
    #[\Override]
    protected function dispatch(bool $blocking): void
    {
        if ($this->signalHandling) {
            \pcntl_signal_dispatch();

            while (!$this->signalQueue->isEmpty()) {
                $signal = $this->signalQueue->dequeue();

                foreach ($this->signalCallbacks[$signal] as $callback) {
                    $this->enqueueCallback($callback);
                }

                $blocking = false;
            }

            // A handler pcntl_signal_dispatch() just ran may have stopped the loop or changed what it waits for.
            $blocking = $blocking && $this->mayBlock();
        }

        $this->selectStreams(
            $this->readStreams,
            $this->writeStreams,
            $blocking ? $this->getTimeout() : 0.0
        );

        $now = $this->now();

        while ($callback = $this->timerQueue->extract($now)) {
            $this->enqueueCallback($callback);
        }
    }

    #[\Override]
    protected function activate(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            if ($callback instanceof StreamReadableCallback) {
                \assert(\is_resource($callback->stream));

                $streamId = (int) $callback->stream;
                $this->readCallbacks[$streamId][$callback->id] = $callback;
                $this->readStreams[$streamId] = $callback->stream;
            } elseif ($callback instanceof StreamWritableCallback) {
                \assert(\is_resource($callback->stream));

                $streamId = (int) $callback->stream;
                $this->writeCallbacks[$streamId][$callback->id] = $callback;
                $this->writeStreams[$streamId] = $callback->stream;
            } elseif ($callback instanceof TimerCallback) {
                $this->timerQueue->insert($callback);
            } elseif ($callback instanceof SignalCallback) {
                if (!isset($this->signalCallbacks[$callback->signal])) {
                    \set_error_handler(static function (int $errno, string $errstr): bool {
                        throw new UnsupportedFeatureException(
                            \sprintf("Failed to register signal handler; Errno: %d; %s", $errno, $errstr)
                        );
                    });

                    // Avoid bug in Psalm handling of first-class callables by assigning to a temp variable.
                    $handler = $this->handleSignal(...);

                    try {
                        \pcntl_signal($callback->signal, $handler);
                    } finally {
                        \restore_error_handler();
                    }
                }

                $this->signalCallbacks[$callback->signal][$callback->id] = $callback;
            } else {
                // @codeCoverageIgnoreStart
                throw new \Error("Unknown callback type");
                // @codeCoverageIgnoreEnd
            }
        }
    }

    #[\Override]
    protected function deactivate(DriverCallback $callback): void
    {
        if ($callback instanceof StreamReadableCallback) {
            $streamId = (int) $callback->stream;
            unset($this->readCallbacks[$streamId][$callback->id]);
            if (empty($this->readCallbacks[$streamId])) {
                unset($this->readCallbacks[$streamId], $this->readStreams[$streamId]);
            }
        } elseif ($callback instanceof StreamWritableCallback) {
            $streamId = (int) $callback->stream;
            unset($this->writeCallbacks[$streamId][$callback->id]);
            if (empty($this->writeCallbacks[$streamId])) {
                unset($this->writeCallbacks[$streamId], $this->writeStreams[$streamId]);
            }
        } elseif ($callback instanceof TimerCallback) {
            $this->timerQueue->remove($callback);
        } elseif ($callback instanceof SignalCallback) {
            if (isset($this->signalCallbacks[$callback->signal])) {
                unset($this->signalCallbacks[$callback->signal][$callback->id]);

                if (empty($this->signalCallbacks[$callback->signal])) {
                    unset($this->signalCallbacks[$callback->signal]);
                    \set_error_handler(static fn () => true);
                    try {
                        \pcntl_signal($callback->signal, \SIG_DFL);
                    } finally {
                        \restore_error_handler();
                    }
                }
            }
        } else {
            // @codeCoverageIgnoreStart
            throw new \Error("Unknown callback type");
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @param array<int, resource> $read
     * @param array<int, resource> $write
     * @param float|null $timeout Seconds to wait, 0 for non-blocking, null for no limit.
     */
    private function selectStreams(array $read, array $write, ?float $timeout): void
    {
        \assert(IdleSleep::isValidTimeout($timeout), 'Invalid wait timeout');

        if (!empty($read) || !empty($write)) { // Use stream_select() if there are any streams in the loop.
            if ($timeout !== 0.0) {
                // Every blocking stream_select() ends within IdleSleep::SIGNAL_WAIT_CAP. The call casts user-space
                // wrapper streams (stream_cast(), user code) right before its kernel wait, and that code may stop the
                // loop or add a timer after the last mayBlock() check. No re-check fits between the casts and the
                // wait: stream_select() does both in one call, and PHP has no way to cast a stream for select without
                // selecting (a zero-timeout select first would cast again in the blocking call). The cap also bounds
                // a signal that arrives just before the wait, with loop signal callbacks or a handler run
                // asynchronously (pcntl_async_signals(true)).
                $timeout = IdleSleep::capped($timeout);
            }

            $seconds = (int) $timeout;
            $microseconds = (int) (($timeout - $seconds) * 1_000_000);

            // Failed connection attempts are indicated via except on Windows
            // @link https://github.com/reactphp/event-loop/blob/8bd064ce23c26c4decf186c2a5a818c9a8209eb0/src/StreamSelectLoop.php#L279-L287
            // @link https://docs.microsoft.com/de-de/windows/win32/api/winsock2/nf-winsock2-select
            $except = null;
            if (\DIRECTORY_SEPARATOR === '\\') {
                $except = $write;
            }

            \set_error_handler($this->streamSelectErrorHandler);

            try {
                /** @psalm-suppress InvalidArgument */
                $result = \stream_select($read, $write, $except, $seconds, $microseconds);
            } catch (\ValueError $exception) {
                throw new \Error(
                    "stream_select() failed: ensure all callbacks on closed stream resources are cancelled",
                    previous: $exception,
                );
            } finally {
                \restore_error_handler();
            }

            if ($this->streamSelectIgnoreResult || $result === 0) {
                $this->streamSelectIgnoreResult = false;
                return;
            }

            if (!$result) {
                throw new \Exception('Unknown error during stream_select');
            }

            foreach ($read as $stream) {
                $streamId = (int) $stream;
                if (!isset($this->readCallbacks[$streamId])) {
                    continue; // All read callbacks disabled.
                }

                foreach ($this->readCallbacks[$streamId] as $callback) {
                    $this->enqueueCallback($callback);
                }
            }

            /** @var array<int, resource>|null $except */
            if ($except !== null) {
                foreach ($except as $key => $socket) {
                    $write[$key] = $socket;
                }
            }

            foreach ($write as $stream) {
                $streamId = (int) $stream;
                if (!isset($this->writeCallbacks[$streamId])) {
                    continue; // All write callbacks disabled.
                }

                foreach ($this->writeCallbacks[$streamId] as $callback) {
                    $this->enqueueCallback($callback);
                }
            }

            return;
        }

        // Sleep until the next timer expires, or with only signal callbacks enabled until a signal arrives.
        // Only a sleep with signal callbacks dispatches them, and only then can a handler change the loop's state.
        IdleSleep::sleep(
            $timeout,
            \array_keys($this->signalCallbacks),
            $this->signalQueue,
            $this->signalCallbacks ? $this->mayBlock(...) : null,
        );
    }

    /**
     * @return float|null Seconds until the next timer expires (at most IdleSleep::MAX_WAIT), or null if no timer is
     *     pending or the next one never expires.
     */
    private function getTimeout(): ?float
    {
        $expiration = $this->timerQueue->peek();

        return $expiration === null ? null : IdleSleep::untilExpiration($expiration, $this->now());
    }

    private function handleSignal(int $signal): void
    {
        // Queue signals, so we don't suspend inside pcntl_signal_dispatch, which disables signals while it runs
        $this->signalQueue->enqueue($signal);
    }
}
