<?php

declare(strict_types=1);

namespace Fledge\Loop\Internal;

/**
 * The blocking sleep of a driver tick that has no stream to wait on: until the next timer, or with no timer until a
 * signal arrives.
 *
 * usleep() takes at most UINT32_MAX microseconds (about 71.6 minutes) and throws a ValueError above that, so every
 * sleep is capped there; a longer wait is a series of sleeps, one per tick, and the loop wakes at most about once an
 * hour while nothing happens.
 *
 * With signal callbacks enabled the sleep has to end when one of their signals arrives. pcntl dispatches a signal only
 * at the next pcntl_signal_dispatch(), so a signal that arrives after the tick's dispatch and before usleep() starts
 * would otherwise wait for the whole sleep. Where pcntl_sigtimedwait() exists (Linux; not macOS), the sleep is
 * race-free: the loop's signals are blocked, already delivered ones are dispatched, and pcntl_sigtimedwait() then waits
 * for the next one, which it consumes and queues for the driver exactly as the pcntl handler would. Elsewhere the
 * pending signals are dispatched right before usleep(), which narrows that window to a few instructions, and the sleep
 * is capped at SIGNAL_WAIT_CAP so a signal lost in it is dispatched at most that late.
 *
 * With async signal dispatch on (pcntl_async_signals(true)), a plain pcntl_signal() handler runs whenever its signal
 * arrives, also between the loop's last look at its state and the kernel wait, and nothing the loop can block covers a
 * handler it does not know about. Every sleep is then capped at SIGNAL_WAIT_CAP too, so whatever such a handler did
 * (a deferred stop(), a new timer) is acted on at most that late.
 *
 * It also holds the timeout rules shared by both drivers' waits. A timeout is a ?float: null blocks with no limit,
 * anything else is a finite number of seconds from 0 to MAX_WAIT. No sentinel (-1, INF) stands for "no limit", so no
 * such value can survive arithmetic into an int conversion, a Time\Duration or a stream_select() argument.
 *
 * @internal
 */
final class IdleSleep
{
    /** usleep()'s upper bound: UINT32_MAX microseconds. */
    public const int MAX_MICROSECONDS = 4_294_967_295;

    /**
     * Upper bound in seconds for any one blocking wait (usleep()'s bound): a timer further away is reached over several
     * waits. Every backend takes it: as nanoseconds it fits an int, and stream_select() on macOS rejects more than
     * 100,000,000 seconds with EINVAL.
     */
    public const float MAX_WAIT = self::MAX_MICROSECONDS / 1_000_000;

    /** Upper bound in seconds for one pcntl_sigtimedwait() sleep. */
    public const int MAX_SIGNAL_WAIT_SECONDS = 3600;

    /**
     * Upper bound in seconds for a blocking wait that a signal handler, or code run inside the wait call, may have to
     * end.
     *
     * pcntl dispatches a signal only at the next pcntl_signal_dispatch(). A signal that arrives after a tick's dispatch
     * and before the kernel wait starts (stream_select(), the Io\Poll wait, usleep()) does not interrupt that wait:
     * the kernel had not entered it yet. PHP 8.6 offers no atomic way out (no ppoll() or epoll_pwait() signal mask on
     * Io\Poll\Context::wait(), no signalfd, and pcntl_async_signals() runs the PHP handler only after the syscall
     * returns), so with a stream watched and no timer due, such a signal (a SIGTERM for a graceful shutdown) would
     * wait for the next I/O event, possibly forever on an idle server. The same holds for a handler run asynchronously
     * (pcntl_async_signals(true)) in that window: the loop already decided to block. Capping the wait bounds that
     * delay to this many seconds, at the cost of one wakeup per second per process: see {@see self::forSignals()} for
     * when it applies. The race-free pcntl_sigtimedwait() sleep for the loop's own signals (no stream watched, Linux)
     * needs no cap of its own.
     *
     * stream_select() also runs user code inside the wait call: it casts every user-space wrapper stream
     * (stream_cast()) right before the kernel wait, after the loop's last look at its state. Both drivers therefore cap
     * every blocking stream_select() at this bound as well (see {@see self::capped()}).
     */
    public const float SIGNAL_WAIT_CAP = 1.0;

    /** Whether pcntl_async_signals() exists; resolved once. */
    private static ?bool $asyncSignalsAvailable = null;

    /**
     * The timeout of a blocking wait for a timer that expires at $expiration (seconds on the driver's clock).
     *
     * @return float|null Null for a timer that never expires (INF), else seconds from 0 to MAX_WAIT. An expiration
     *     is never NAN: delay() and repeat() reject a NAN duration.
     */
    public static function untilExpiration(float $expiration, float $now): ?float
    {
        $timeout = $expiration - $now;

        if ($timeout > self::MAX_WAIT) {
            return $timeout === \INF ? null : self::MAX_WAIT;
        }

        return $timeout > 0 ? $timeout : 0.0;
    }

    /**
     * Whether $timeout is a valid wait timeout: null (no limit) or finite seconds from 0 to MAX_WAIT.
     */
    public static function isValidTimeout(?float $timeout): bool
    {
        return $timeout === null || ($timeout >= 0 && $timeout <= self::MAX_WAIT);
    }

    /**
     * @param float|null $timeout Seconds to sleep, null for no limit (until a signal arrives).
     * @param list<int> $signals The signals with an enabled loop callback.
     * @param \SplQueue<int> $queue The driver's signal queue, which the pcntl handler fills.
     * @param (\Closure(): bool)|null $mayBlock Asked again after the pending signals were dispatched here: a handler
     *     that ran may have stopped the loop or given it work, and then the sleep does not start.
     */
    public static function sleep(?float $timeout, array $signals, \SplQueue $queue, ?\Closure $mayBlock = null): void
    {
        \assert(self::isValidTimeout($timeout), 'Invalid wait timeout');

        if ($timeout === 0.0) {
            return;
        }

        // A handler run asynchronously, after the caller's last mayBlock() check, cannot end this sleep itself.
        $timeout = self::forSignals($timeout, false);

        if ($signals !== [] && \function_exists('pcntl_sigtimedwait') && \function_exists('pcntl_sigprocmask')) {
            self::waitForSignal($timeout, $signals, $queue, $mayBlock);

            return;
        }

        if ($signals !== []) {
            \pcntl_signal_dispatch();

            if (!$queue->isEmpty() || ($mayBlock !== null && !$mayBlock())) {
                return;
            }

            $timeout = self::capped($timeout);
        }

        \usleep($timeout === null ? self::MAX_MICROSECONDS : (int) \min($timeout * 1_000_000, self::MAX_MICROSECONDS));
    }

    /**
     * $timeout capped at {@see self::SIGNAL_WAIT_CAP} seconds.
     *
     * @param float|null $timeout Seconds, null for no limit.
     */
    public static function capped(?float $timeout): float
    {
        return $timeout === null || $timeout > self::SIGNAL_WAIT_CAP ? self::SIGNAL_WAIT_CAP : $timeout;
    }

    /**
     * The timeout of a blocking wait that a signal may have to end: capped at {@see self::SIGNAL_WAIT_CAP} seconds while
     * loop signal callbacks are enabled ($signalCallbacks) or async signal dispatch is on (pcntl_async_signals()),
     * unchanged otherwise.
     *
     * A timeout within the cap (every zero-timeout tick among them) returns at once, so pcntl_async_signals() is asked
     * only before a wait longer than the cap; it costs about 2 ns on macOS and 5 ns in a Linux container.
     *
     * @param float|null $timeout Seconds, null for no limit.
     */
    public static function forSignals(?float $timeout, bool $signalCallbacks): ?float
    {
        if ($timeout !== null && $timeout <= self::SIGNAL_WAIT_CAP) {
            return $timeout;
        }

        if ($signalCallbacks || self::asyncSignals()) {
            return self::SIGNAL_WAIT_CAP;
        }

        return $timeout;
    }

    /**
     * Whether pcntl dispatches signals asynchronously (pcntl_async_signals(true)) right now. The setting can change at
     * any time, so it is read before every wait that could be capped.
     */
    private static function asyncSignals(): bool
    {
        self::$asyncSignalsAvailable ??= \function_exists('pcntl_async_signals');

        return self::$asyncSignalsAvailable && \pcntl_async_signals();
    }

    /**
     * @param list<int> $signals
     * @param \SplQueue<int> $queue
     * @param (\Closure(): bool)|null $mayBlock
     */
    private static function waitForSignal(?float $timeout, array $signals, \SplQueue $queue, ?\Closure $mayBlock): void
    {
        if ($timeout === null || $timeout >= self::MAX_SIGNAL_WAIT_SECONDS) {
            $seconds = self::MAX_SIGNAL_WAIT_SECONDS;
            $nanoseconds = 0;
        } else {
            $seconds = (int) $timeout;
            $nanoseconds = (int) (($timeout - $seconds) * 1_000_000_000);

            if ($seconds === 0 && $nanoseconds === 0) {
                return;
            }
        }

        \pcntl_sigprocmask(\SIG_BLOCK, $signals, $previous);

        try {
            // A signal delivered before the block went to its pcntl handler; one arriving from here on stays pending
            // in the kernel until pcntl_sigtimedwait() takes it.
            \pcntl_signal_dispatch();

            if (!$queue->isEmpty() || ($mayBlock !== null && !$mayBlock())) {
                return;
            }

            // False on timeout, and on EINTR (another signal with a handler) with a warning that is not ours to show.
            $signal = @\pcntl_sigtimedwait($signals, $info, $seconds, $nanoseconds);

            if (\is_int($signal) && $signal > 0) {
                $queue->enqueue($signal);
            }
        } finally {
            \pcntl_sigprocmask(\SIG_SETMASK, $previous);
        }
    }
}
