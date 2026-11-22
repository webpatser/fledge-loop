<?php

declare(strict_types=1);

namespace Fledge\Loop;

use const FiberIo\READABLE;
use const FiberIo\WRITABLE;

use Fledge\Loop;
use Io\Poll\Backend;
use Io\Poll\Context;
use Io\Poll\Event;
use Io\Poll\FailedPollWaitException;
use Time\Duration;

/**
 * Waiter for FiberIo\enable() that parks the current fiber on the
 * event loop until the stream is ready or the timeout expires.
 *
 *     \FiberIo\enable(new FiberIoWaiter);
 *
 * fiberio only calls the waiter from inside a running fiber. One of those
 * fibers is the event loop's own: with HOOK_SLEEP on, the loop's idle
 * usleep() until the next timer is hooked too. That fiber cannot park on
 * itself, so there the waiter never suspends: a timer wait hands the sleep
 * back to fiberio, which blocks, and a stream wait blocks in poll(2)
 * (see waitInLoopFiber()).
 *
 * This is the canonical event loop waiter for php-fiberio, which no longer
 * ships one from 0.3.0 on.
 */
final class FiberIoWaiter
{
    /**
     * @param  resource|null  $stream  Stream wrapping a dup of the socket, only for watching; null for a timer wait (events 0).
     * @param  int  $events  Bitmask of FiberIo\READABLE and FiberIo\WRITABLE.
     * @param  float|null  $timeout  Seconds, or null to wait without a limit. INF counts as null, a negative value as
     *     zero.
     * @return bool True when the stream is ready, false on timeout.
     *
     * @throws \InvalidArgumentException When $timeout is NAN, or a stream wait has no stream.
     */
    public function __invoke($stream, int $events, ?float $timeout): bool
    {
        if ($timeout !== null && !($timeout < \INF)) {
            if (\is_nan($timeout)) {
                throw new \InvalidArgumentException('FiberIoWaiter: the timeout must be a number of seconds or null, NAN given');
            }

            $timeout = null;
        }

        if (Loop::inLoopFiber()) {
            return self::waitInLoopFiber($stream, $events, $timeout);
        }

        if ($stream === null && $events !== 0) {
            throw new \InvalidArgumentException('FiberIoWaiter: a stream wait (events other than 0) needs a stream');
        }

        /** @var Suspension<bool> $suspension */
        $suspension = Loop::getSuspension();
        $done = false;

        // Several watchers can fire in the same tick: only the first resumes.
        $ready = static function () use ($suspension, &$done): void {
            if (! $done) {
                $done = true;
                $suspension->resume(true);
            }
        };

        $ids = [];

        try {
            if ($stream !== null && $events & READABLE) {
                $ids[] = Loop::onReadable($stream, $ready);
            }
            if ($stream !== null && $events & WRITABLE) {
                $ids[] = Loop::onWritable($stream, $ready);
            }
            if ($timeout !== null) {
                $ids[] = Loop::delay(\max(0.0, $timeout), static function () use ($suspension, &$done): void {
                    if (! $done) {
                        $done = true;
                        $suspension->resume(false);
                    }
                });
            }

            return $suspension->suspend();
        } finally {
            foreach ($ids as $id) {
                Loop::cancel($id);
            }
        }
    }

    /**
     * Wait without suspending, for a call from the event loop fiber itself.
     *
     * A timer call returns false ("deadline not reached") right away:
     * fiberio retries once and then sleeps the remainder blocking, which is
     * exactly the stock usleep() the loop asked for. True would mean "woken",
     * which ends a hooked usleep() at once and makes the loop busy-spin on
     * its idle sleep. A stream call blocks in poll(2) on the watch-only dup.
     *
     * @param  resource|null  $stream
     */
    private static function waitInLoopFiber($stream, int $events, ?float $timeout): bool
    {
        if ($stream === null || $events === 0) {
            return false;
        }

        $watch = [];
        if ($events & READABLE) {
            $watch[] = Event::Read;
        }
        if ($events & WRITABLE) {
            $watch[] = Event::Write;
        }

        // A fresh poll(2) context per call: no FD_SETSIZE limit like
        // stream_select(), no kernel object to leak across fork, and the
        // watched dup may differ between calls. It costs about 300 ns more
        // than stream_select() on a ready socket. add() throws
        // InvalidHandleException for a stream it cannot watch; that, like
        // any wait failure other than EINTR, propagates to fiberio, which
        // reports the I/O as failed.
        $context = new Context(Backend::Poll);
        $context->add(new \StreamPollHandle($stream), $watch);

        $deadline = $timeout === null ? null : \hrtime(true) + (int) \ceil(\max(0.0, $timeout) * 1_000_000_000);

        for (;;) {
            $remaining = $deadline === null ? null : Duration::fromNanoseconds(\max(0, $deadline - \hrtime(true)));

            try {
                // HangUp and Error are reported without being asked for and
                // count as ready, as they did for stream_select(): the next
                // read or write surfaces the real error.
                return $context->wait($remaining, 1) !== [];
            } catch (FailedPollWaitException $exception) {
                if ($exception->getCode() !== FailedPollWaitException::ERROR_INTERRUPTED) {
                    throw $exception;
                }
                // EINTR: wait again for what is left, like fiberio's own poll().
                if ($deadline !== null && \hrtime(true) >= $deadline) {
                    return false;
                }
            }
        }
    }
}
