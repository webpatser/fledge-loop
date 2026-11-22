<?php

declare(strict_types=1);

namespace Fledge\Loop\Driver;

use Fledge\Loop\Internal\AbstractDriver;
use Fledge\Loop\Internal\ClosureHelper;
use Fledge\Loop\Internal\DriverCallback;
use Fledge\Loop\Internal\IdleSleep;
use Fledge\Loop\Internal\SignalCallback;
use Fledge\Loop\Internal\StreamReadableCallback;
use Fledge\Loop\Internal\StreamWritableCallback;
use Fledge\Loop\Internal\TimerCallback;
use Fledge\Loop\Internal\TimerQueue;
use Fledge\Loop\LoopStateException;
use Fledge\Loop\UnsupportedFeatureException;
use Io\Poll\Backend;
use Io\Poll\Context;
use Io\Poll\Event;
use Io\Poll\FailedPollWaitException;
use Io\Poll\Watcher;
use Time\Duration;

/**
 * Event loop driver on PHP 8.6's Io\Poll (kqueue on macOS and BSD, epoll on Linux, poll(2) anywhere).
 *
 * Unlike StreamSelectDriver it has no FD_SETSIZE limit, and on the kqueue and epoll backends idle descriptors add
 * only a few nanoseconds each to a wait instead of a select() scan. Behaviour (callback order, timers, signals)
 * follows StreamSelectDriver.
 *
 * Streams: one Io\Poll\Watcher per stream carries the merged read/write interest of all enabled callbacks for that
 * stream, because a Context refuses the same descriptor twice. Interest changes are collected and applied once per
 * tick, right before the wait: the watcher is modified when the interest changes, removed when it becomes empty (an
 * empty event list is rejected by the API) and added again when it returns.
 *
 * Fallback set: some streams cannot be added to a backend (character devices such as /dev/null on kqueue and on
 * macOS poll(2), which accepts them but never reports them ready; a second resource for an already watched
 * descriptor), and user-space wrapper streams, whose stat may not match their cast, are not trusted to it. Those
 * streams are kept in a fallback set that is checked every tick with a zero-timeout
 * stream_select(). While the fallback set is non-empty next to backend watchers, the backend wait is capped at
 * FALLBACK_INTERVAL seconds, so a fallback stream is noticed at most that late. The trade-off is up to 100 extra
 * wakeups per second while such a stream is watched (a few microseconds of CPU each) in exchange for not running
 * select() over every descriptor. When only fallback streams exist, stream_select() itself is the blocking
 * call and there is no added latency.
 *
 * Regular files: POSIX select() and poll() report a regular file always ready for read and write, at EOF too, and
 * stream_select() does. kqueue reports a read only while unread bytes remain (an empty file or one read to its end
 * never becomes readable) and epoll refuses regular files. PollDriver therefore never hands a regular file to the
 * backend: it is ready every tick on every backend, as with StreamSelectDriver, and an enabled callback on one makes
 * every tick non-blocking, as it does there.
 *
 * Streams neither the backend nor stream_select() can represent (php://memory) also land in the fallback set, so
 * they fail exactly as in StreamSelectDriver: registering succeeds and the next dispatch throws the same exception
 * out of run().
 *
 * Closed streams: cancel a stream's callbacks before closing the stream (see {@see \Fledge\Loop::onReadable()}). A
 * stream closed while one of its callbacks is enabled is a programming error: kqueue and epoll drop a closed
 * descriptor without reporting an event, so nothing would ever wake its callbacks. How the driver reacts:
 *
 * - Debug mode (assertions active, zend.assertions=1, or env FLEDGE_DEBUG truthy; both are read once, when the
 *   driver is created, so a later putenv() or ini_set() has no effect on it): before every wait the driver checks
 *   every watched stream and throws an \Error out of run() (or the suspend() running the loop) at that tick, naming
 *   the stream's callbacks and where their closures are defined. It is not passed to the loop's error handler.
 * - Production: there is no per-tick scan. A safety net sweeps at most SWEEP_BUDGET watchers, round-robin, at most
 *   once per SWEEP_INTERVAL second (while streams are watched, a blocking wait ends by the next sweep). A stream it
 *   finds closed is treated as ready for read and write, so its callbacks run late (a reader sees an invalid stream)
 *   instead of never. A full round over W watchers takes ceil(W / SWEEP_BUDGET) sweeps: with 20,000 watchers 5
 *   sweeps, so a stream closed while watched is found at most about 6 seconds later (up to about 10 seconds for a
 *   stream first watched while a round was under way, which waits for the next round).
 *
 * PHP closes some streams the caller still holds (proc_close() and a dropped proc_open() process handle close its
 * pipes, socket_close() closes an imported or exported stream): {@see \Fledge\Loop::onReadable()} lists them.
 *
 * Idle: while streams are watched, a production loop with no timer or signal callback wakes once per SWEEP_INTERVAL
 * for the sweep. A back-off after quiet rounds was considered and left out: a close without cancel is invisible to
 * the loop, so a quiet round predicts nothing, and backing off to 5 s would raise the worst case at 20,000 watchers
 * from about 6 to about 26 seconds to save about 1 to 2 ms of CPU per second (mostly Io\Poll's own wait cost).
 *
 * A stream found closed while its interest is synced (enabled, disabled) is treated the same way at once.
 * StreamSelectDriver, for the same mistake, throws an \Error ("ensure all callbacks on closed stream resources are
 * cancelled") out of run() at the next tick in every mode, because stream_select() refuses closed streams. Hangup and
 * error events from the backend wake both the read and the write callbacks of the stream.
 *
 * Signals: pcntl_signal() and pcntl_signal_dispatch(), as in StreamSelectDriver. A signal arriving during the
 * backend wait interrupts it; the interrupted wait counts as zero events and the signal is dispatched next tick. One
 * arriving just before the wait starts cannot interrupt it, so while signal callbacks are enabled, and whenever async
 * signal dispatch is on (pcntl_async_signals(true), whose handlers may run after the loop decided to block), every
 * wait is capped at IdleSleep::SIGNAL_WAIT_CAP (1 s), in both drivers. A blocking stream_select() over the fallback
 * set alone is always capped there: it runs user-space wrappers' stream_cast() right before its kernel wait.
 *
 * Descriptor: the Io\Poll\Context (and with it the kqueue or epoll descriptor) is opened lazily, at the first
 * stream watcher or the first {@see self::getHandle()} call. A process that only uses timers, deferred callbacks,
 * signals and suspensions holds no descriptor, and one that forks before any stream I/O has nothing to abandon.
 *
 * Fork: the driver belongs to the process that created it. A forked child inherits the epoll descriptor on Linux
 * (shared with the parent: registering, modifying or removing a watcher in the child changes the parent's
 * registrations, and the parent then receives events whose data refers to the child's memory) and loses the kqueue
 * descriptor on macOS and BSD (its number becomes free and is reused by the child's next descriptor). The driver
 * therefore refuses to touch the kernel object from any process but its creator: the sync of watcher changes and
 * every wait on the Context throw a {@see LoopStateException} (a LogicException) naming
 * {@see \Fledge\Loop::afterFork()}, which is what a forked child calls first to discard the inherited driver and start
 * a fresh loop. The check is one getmypid() per tick
 * that syncs or waits, made right before the first of those kernel calls (no user code runs between them); it costs
 * about 3 ns on macOS and about 125 ns in a Linux container, where getpid() is a real syscall. Ticks without
 * watchers, which sleep or select instead, do not pay it. Destroying the driver in a process
 * that did not create it issues no kernel operation and leaves the signal dispositions alone.
 *
 * Loop::afterFork() is the only full protection. Without it, a shutdown function (registered once per process, at
 * the first Context) releases inherited Contexts in a child before PHP closes the remaining streams, so a plain child
 * exit leaves the parent's registrations intact. That hook is best effort: a shutdown function registered before it
 * runs first, and if that one closes a stream the parent watches, the still-alive inherited Context deletes the
 * parent's registration. Fibers suspended in the parent unwind in the child either way; fork from a quiet point.
 */
final class PollDriver extends AbstractDriver
{
    /** Upper bound in seconds for a backend wait while select-checked fallback streams are watched. */
    private const float FALLBACK_INTERVAL = 0.01;

    private const int READ = 1;

    private const int WRITE = 2;

    private const int S_IFMT = 0170000;

    private const int S_IFREG = 0100000;

    private const int S_IFCHR = 0020000;

    /** fileType() result for a user-space wrapper stream: always checked with stream_select() (fallback set). */
    private const int USER_SPACE = -1;

    /** Environment variable that turns on debug mode (the closed-stream check before every wait) without assertions. */
    public const string DEBUG_ENV = 'FLEDGE_DEBUG';

    /** Seconds between two production safety-net sweeps for streams closed while watched. */
    private const float SWEEP_INTERVAL = 1.0;

    /** Watchers checked per safety-net sweep. */
    private const int SWEEP_BUDGET = 4096;

    /** Assertions active or FLEDGE_DEBUG set: a stream closed while watched throws instead of being swept. */
    private readonly bool $debug;

    /** Loop time (now()) at or after which the next safety-net sweep runs; null in debug mode, which has no sweep. */
    private ?float $nextSweep;

    /** @var list<int> Stream ids of the current safety-net round, a snapshot of the watchers when it began. */
    private array $sweepRound = [];

    /** Position of the next stream id to check in $sweepRound. */
    private int $sweepPosition = 0;

    /** Opened at the first stream watcher or getHandle() call; null again once released. */
    private ?Context $context = null;

    private readonly Backend $backend;

    /** Set once the Context was released (abandoned, or found inherited): it is never opened again. */
    private bool $abandoned = false;

    /**
     * Inherited kqueue Contexts released in a forked child. The (not inherited) descriptor's number may already
     * belong to another descriptor of the child, so these are never closed before the process exits.
     *
     * @var list<Context>
     */
    // @phpstan-ignore property.onlyWritten (held only so the parked descriptors stay open until exit)
    private static array $parkedContexts = [];

    /** @var \WeakMap<self, true>|null Drivers that opened a Context, for the exit hook. */
    private static ?\WeakMap $withContext = null;

    /** Inherited by a forked child together with the registered hook itself. */
    private static bool $exitHookRegistered = false;

    /** @var array<int, resource> */
    private array $streams = [];

    /** @var array<int, array<string, StreamReadableCallback>> */
    private array $readCallbacks = [];

    /** @var array<int, array<string, StreamWritableCallback>> */
    private array $writeCallbacks = [];

    /** @var array<int, Watcher> */
    private array $watchers = [];

    /** @var array<int, int> Interest bitmask currently registered with each watcher. */
    private array $watcherInterest = [];

    /** @var array<int, true> Streams whose interest changed since the last sync. */
    private array $dirty = [];

    /** @var array<int, resource> Rejected by the backend, checked with stream_select() every tick. */
    private array $selectStreams = [];

    /** @var array<int, true> Regular files and streams closed while watched: ready until their callbacks are gone. */
    private array $readyStreams = [];

    private TimerQueue $timerQueue;

    /** @var array<int, array<string, SignalCallback>> */
    private array $signalCallbacks = [];

    /** @var \SplQueue<int> */
    private readonly \SplQueue $signalQueue;

    private readonly bool $signalHandling;

    private readonly Duration $zero;

    /**
     * macOS poll(2) accepts character devices such as /dev/null but never reports them ready, so on the Poll backend
     * there they go to the fallback set instead of the backend.
     */
    private bool $rejectCharacterDevices = false;

    private readonly \Closure $streamSelectErrorHandler;

    private bool $streamSelectIgnoreResult = false;

    public function __construct(Backend $backend = Backend::Auto)
    {
        parent::__construct();

        $this->backend = $backend;
        $this->signalQueue = new \SplQueue();
        $this->timerQueue = new TimerQueue();
        $this->zero = Duration::fromSeconds(0);
        $this->debug = self::debugMode();
        $this->nextSweep = $this->debug ? null : $this->now() + self::SWEEP_INTERVAL;
        $this->signalHandling = \extension_loaded('pcntl')
            && \function_exists('pcntl_signal_dispatch')
            && \function_exists('pcntl_signal');

        $this->streamSelectErrorHandler = function (int $errno, string $message): void {
            if (\stripos($message, 'stream_select(): unable to select [4]: ') === 0) { // EINTR
                $this->streamSelectIgnoreResult = true;

                return;
            }

            throw new \Exception($message, $errno);
        };
    }

    public function __destruct()
    {
        if (\getmypid() !== $this->creatorPid) {
            // Inherited through fork(): the signal handlers belonged to the parent's loop (Loop::afterFork() resets
            // them in the child), and the kernel object is not ours to touch.
            $this->releaseContext();

            return;
        }

        foreach ($this->signalCallbacks as $signalCallbacks) {
            foreach ($signalCallbacks as $signalCallback) {
                $this->deactivate($signalCallback);
            }
        }
    }

    /**
     * Releases the Context first, with no kernel operation on the shared object (no remove, no modify): on epoll and
     * poll(2) the child's copy of the descriptor is closed, which leaves the parent's registrations intact; on kqueue,
     * which a child does not inherit, the Context is parked until the process exits, because closing its descriptor
     * number could close an unrelated descriptor the child has opened since. Only then are the callbacks dropped
     * (while an inherited Context is alive, closing a stream it watches deletes the parent's epoll registration),
     * and the pcntl signal handlers this driver installed are reset to SIG_DFL.
     *
     * @internal For {@see \Fledge\Loop}; not part of the public API.
     *
     * @return \WeakReference<Context>|null The released Context, to verify that nothing else keeps it alive; null when
     *     there was none or it was parked.
     */
    #[\Override]
    public function abandonAfterFork(): ?\WeakReference
    {
        $released = $this->releaseContext();
        $this->abandoned = true;

        foreach (\array_keys($this->signalCallbacks) as $signal) {
            \set_error_handler(static fn () => true);
            try {
                \pcntl_signal($signal, \SIG_DFL);
            } finally {
                \restore_error_handler();
            }
        }

        $this->signalCallbacks = [];
        $this->streams = [];
        $this->readCallbacks = [];
        $this->writeCallbacks = [];
        $this->selectStreams = [];
        $this->readyStreams = [];
        $this->sweepRound = [];
        $this->sweepPosition = 0;
        $this->timerQueue = new TimerQueue();

        while (!$this->signalQueue->isEmpty()) {
            $this->signalQueue->dequeue();
        }

        parent::abandonAfterFork();

        return $released;
    }

    /**
     * Drops the Context and every watcher without a kernel operation; in a forked child a kqueue Context is parked
     * instead of closed. The driver never opens a Context again afterwards.
     *
     * @return \WeakReference<Context>|null
     */
    private function releaseContext(): ?\WeakReference
    {
        $context = $this->context;
        $this->context = null;
        $this->abandoned = true;
        $this->watchers = [];
        $this->watcherInterest = [];
        $this->dirty = [];

        if ($context === null) {
            return null;
        }

        if (\getmypid() !== $this->creatorPid && $context->getBackend() === Backend::Kqueue) {
            self::$parkedContexts[] = $context;

            return null;
        }

        return \WeakReference::create($context);
    }

    /**
     * Shutdown function, registered once per process at the first Context (and inherited by forked children): in a
     * child that exits without Loop::afterFork(), releases every inherited Context before PHP closes the remaining
     * streams. An inherited Context lives in the loop's static driver, which PHP frees after the resources, and on
     * epoll each close of a stream it watches would delete the parent's registration.
     */
    private static function releaseInheritedContexts(): void
    {
        $pid = \getmypid();

        foreach (self::$withContext ?? [] as $driver => $_) {
            if ($driver->creatorPid !== $pid) {
                $driver->releaseContext();
            }
        }
    }

    /**
     * Debug mode is on when assertions are active or FLEDGE_DEBUG is truthy (1, true, on, yes).
     */
    private static function debugMode(): bool
    {
        // zend.assertions is 1 exactly when assert() runs (0 skips it, -1 compiles it away).
        return \ini_get('zend.assertions') === '1'
            || \filter_var(\getenv(self::DEBUG_ENV), \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * Whether Backend::Auto resolves to kqueue or epoll here. The poll(2) backend is never chosen for the loop
     * (slower than select from about 400 descriptors, EINVAL past OPEN_MAX on macOS); it stays reachable only through
     * an explicit `new PollDriver(Backend::Poll)`.
     */
    public static function isSupported(): bool
    {
        return Backend::Kqueue->isAvailable() || Backend::Epoll->isAvailable();
    }

    /**
     * @throws UnsupportedFeatureException If the pcntl extension is not available.
     */
    #[\Override]
    public function onSignal(int $signal, \Closure $closure): string
    {
        if (!$this->signalHandling) {
            throw new UnsupportedFeatureException('Signal handling requires the pcntl extension');
        }

        return parent::onSignal($signal, $closure);
    }

    /**
     * @return Context|null The driver's Io\Poll\Context, opened by this call if no stream watcher has opened it yet;
     *     null once the driver was abandoned (Fledge\Loop::afterFork(), Fledge\Loop::install()) or released in a
     *     forked child, and in a forked child that inherited the driver before it had a Context.
     */
    #[\Override]
    public function getHandle(): mixed
    {
        if ($this->context !== null || $this->abandoned || \getmypid() !== $this->creatorPid) {
            return $this->context;
        }

        return $this->openContext();
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
        }

        // One process check per dispatch covers both the sync and the wait, unless the sync released a stream or
        // watcher reference: that can run user code (a destructor, a wrapper's stream_close()) that forks, so the
        // wait then checks again.
        $context = null;

        if ($this->dirty && !$this->sync($context = $this->ownContext())) {
            $context = null;
        }

        if ($this->debug) {
            $this->detectClosedStreams();
        }

        // Signal handlers run by pcntl_signal_dispatch() and code run while the sync released a stream may have
        // stopped the loop, changed what it waits for or changed interest again (left for the next tick's sync).
        $blocking = $blocking && $this->dirty === [] && $this->mayBlock();

        $timeout = $blocking ? $this->getTimeout() : 0.0;

        if ($this->readyStreams) {
            $timeout = 0.0;
        } elseif ($timeout !== 0.0 && $this->watchers && $this->nextSweep !== null) {
            // The safety net must get its turn even when nothing else would end the wait.
            $untilSweep = \max(0.0, $this->nextSweep - $this->now());

            if ($timeout === null || $timeout > $untilSweep) {
                $timeout = $untilSweep;
            }
        }

        $this->wait($timeout, $context);

        $now = $this->now();

        if ($this->nextSweep !== null && $now >= $this->nextSweep) {
            $this->sweep($now);
        }

        if ($this->readyStreams) {
            $this->dispatchReadyStreams();
        }

        while ($callback = $this->timerQueue->extract($now)) {
            $this->enqueueCallback($callback);
        }
    }

    #[\Override]
    protected function activate(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            if ($callback instanceof StreamReadableCallback) {
                $streamId = (int) $callback->stream;
                $this->readCallbacks[$streamId][$callback->id] = $callback;
                $this->streams[$streamId] = $callback->stream;
                $this->dirty[$streamId] = true;
            } elseif ($callback instanceof StreamWritableCallback) {
                $streamId = (int) $callback->stream;
                $this->writeCallbacks[$streamId][$callback->id] = $callback;
                $this->streams[$streamId] = $callback->stream;
                $this->dirty[$streamId] = true;
            } elseif ($callback instanceof TimerCallback) {
                $this->timerQueue->insert($callback);
            } elseif ($callback instanceof SignalCallback) {
                if (!isset($this->signalCallbacks[$callback->signal])) {
                    \set_error_handler(static function (int $errno, string $errstr): bool {
                        throw new UnsupportedFeatureException(
                            \sprintf('Failed to register signal handler; Errno: %d; %s', $errno, $errstr)
                        );
                    });

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
                throw new \Error('Unknown callback type');
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
                unset($this->readCallbacks[$streamId]);
                $this->dirty[$streamId] = true;
            }
        } elseif ($callback instanceof StreamWritableCallback) {
            $streamId = (int) $callback->stream;
            unset($this->writeCallbacks[$streamId][$callback->id]);
            if (empty($this->writeCallbacks[$streamId])) {
                unset($this->writeCallbacks[$streamId]);
                $this->dirty[$streamId] = true;
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
            throw new \Error('Unknown callback type');
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * Applies the interest changes collected since the last tick to the backend and the fallback set.
     */
    private function sync(Context $context): bool
    {
        $checked = true;

        // Taken before the loop: a stream released below can run user code that changes interest again, which must
        // stay in $dirty for the next sync rather than be cleared with the entries handled here.
        $dirty = $this->dirty;
        $this->dirty = [];

        foreach ($dirty as $streamId => $_) {
            if (!$checked) {
                // A reference released below may have run user code that forked: check before the next kernel call.
                $context = $this->ownContext();
                $checked = true;
            }

            $interest = (isset($this->readCallbacks[$streamId]) ? self::READ : 0)
                | (isset($this->writeCallbacks[$streamId]) ? self::WRITE : 0);

            if ($interest === 0) {
                $this->forget($streamId);
                $checked = false;

                continue;
            }

            if (isset($this->readyStreams[$streamId]) || isset($this->selectStreams[$streamId])) {
                continue; // Fallback streams take their interest from the callback maps every tick.
            }

            $stream = $this->streams[$streamId];

            if (!\is_resource($stream)) {
                $this->markClosed($streamId);
                $checked = false;

                continue;
            }

            $watcher = $this->watchers[$streamId] ?? null;

            if ($watcher !== null) {
                if (!$watcher->isActive()) {
                    $this->markClosed($streamId);
                    $checked = false;
                } elseif ($this->watcherInterest[$streamId] !== $interest) {
                    try {
                        $watcher->modifyEvents(self::events($interest));
                        $this->watcherInterest[$streamId] = $interest;
                    } catch (\Throwable) {
                        $this->removeWatcher($streamId);
                        $checked = false;
                        $this->addFallback($streamId, $stream);
                    }
                }

                continue;
            }

            // Every new watcher is classified, local or not: a user-space wrapper registered with STREAM_IS_URL
            // reports itself not local, and must still take the select-checked path.
            $fileType = self::fileType($stream);

            if ($fileType === self::S_IFREG) {
                // Always ready for read and write, as stream_select() reports it (see fileType()): on every backend
                // such a stream skips the backend and stays in the ready set until its callbacks are cancelled.
                $this->readyStreams[$streamId] = true;

                continue;
            }

            if ($fileType === self::USER_SPACE || ($this->rejectCharacterDevices && $fileType === self::S_IFCHR)) {
                $this->addFallback($streamId, $stream);

                continue;
            }

            // Context::add() warns before it throws for streams it cannot represent (php://memory).
            \set_error_handler(static fn (): bool => true);

            try {
                $this->watchers[$streamId] = $context->add(
                    new \StreamPollHandle($stream),
                    self::events($interest),
                    $streamId,
                );
                $this->watcherInterest[$streamId] = $interest;
            } catch (\Throwable) {
                $this->addFallback($streamId, $stream);
            } finally {
                \restore_error_handler();
            }
        }

        return $checked;
    }

    /**
     * @return list<Event>
     */
    private static function events(int $interest): array
    {
        return match ($interest) {
            self::READ => [Event::Read],
            self::WRITE => [Event::Write],
            default => [Event::Read, Event::Write],
        };
    }

    /**
     * The file type stream_select() sees for a stream: the descriptor it casts the stream to. POSIX select() and
     * poll() report a regular file always ready for read and write, at EOF too; kqueue reports a read only while
     * unread bytes remain and epoll refuses regular files, so PollDriver keeps everything classified S_IFREG here out
     * of the backend and ready every tick, matching stream_select() on every backend.
     *
     * Measured on macOS 27 (kqueue, poll(2)) and Linux (epoll, poll(2)), PHP 8.6, read and write interest, empty,
     * unread and at EOF (stream_select(): R = readable, W = writable):
     *
     * | stream_type | stream                              | stream_select()     | classified here                 |
     * |-------------|-------------------------------------|---------------------|---------------------------------|
     * | STDIO       | regular file                        | R and W always      | S_IFREG (fstat)                 |
     * | STDIO       | pipe, FIFO, tty                     | R only with data    | its own type: backend           |
     * | STDIO       | /dev/null, /dev/zero                | R and W always      | S_IFCHR: backend or fallback    |
     * | TEMP        | php://temp, small or spilled        | R and W always (1)  | S_IFREG                         |
     * | user-space  | any wrapper, local or URL           | as its cast         | USER_SPACE: fallback (2)        |
     * | MEMORY etc. | php://memory, zlib, dir, no cast    | throws (no cast)    | null: fails as select does      |
     * | sockets     | tcp, udp, unix (not local)          | R only with data    | not classified: backend         |
     *
     * (1) The cast for select() spills a small php://temp to a temporary file. (2) A wrapper's stream_stat() is its
     * own word, not the descriptor's: one can claim a regular file and cast to a socket or pipe. A user-space wrapper
     * stream therefore never goes to the always-ready set or the backend; the select-checked fallback set asks
     * stream_select() every tick, which reports exactly what StreamSelectDriver would (one zero-timeout select per
     * tick while such a stream is watched, plus the FALLBACK_INTERVAL wait cap).
     *
     * Nothing that can block (pipe, FIFO, tty, socket) is classified S_IFREG on any backend.
     *
     * @param resource $stream
     * @return int|null S_IFREG, S_IFCHR or another S_IFMT value; USER_SPACE for a user-space wrapper; null when the
     *     type does not decide anything.
     */
    private static function fileType(mixed $stream): ?int
    {
        $type = \stream_get_meta_data($stream)['stream_type'];

        if ($type === 'TEMP') {
            return self::S_IFREG;
        }

        if ($type === 'user-space') {
            return self::USER_SPACE;
        }

        if ($type !== 'STDIO') {
            return null;
        }

        $stat = @\fstat($stream);

        if (!\is_array($stat)) {
            return null;
        }

        return $stat['mode'] & self::S_IFMT;
    }

    /**
     * @param resource $stream
     */
    private function addFallback(int $streamId, mixed $stream): void
    {
        if (!\is_resource($stream)) {
            $this->markClosed($streamId);

            return;
        }

        $this->selectStreams[$streamId] = $stream;
    }

    private function markClosed(int $streamId): void
    {
        if ($this->debug) {
            throw $this->closedWhileWatched($streamId);
        }

        $this->removeWatcher($streamId);
        unset($this->selectStreams[$streamId]);
        $this->readyStreams[$streamId] = true;
    }

    private function forget(int $streamId): void
    {
        $this->removeWatcher($streamId);
        unset($this->streams[$streamId], $this->selectStreams[$streamId], $this->readyStreams[$streamId]);
    }

    private function removeWatcher(int $streamId): void
    {
        $watcher = $this->watchers[$streamId] ?? null;

        if ($watcher === null) {
            return;
        }

        unset($this->watchers[$streamId], $this->watcherInterest[$streamId]);

        try {
            $watcher->remove();
        } catch (\Throwable) {
            // Already inactive, typically because the stream was closed.
        }
    }

    /**
     * Debug mode: the full check before every wait. The backend drops a closed descriptor without reporting an event;
     * a stream closed while watched throws (markClosed()).
     */
    private function detectClosedStreams(): void
    {
        foreach ($this->watchers as $streamId => $watcher) {
            if (!$watcher->isActive()) {
                $this->markClosed($streamId);
            }
        }

        foreach ($this->readyStreams as $streamId => $_) {
            if (!\is_resource($this->streams[$streamId])) {
                $this->markClosed($streamId);
            }
        }
    }

    /**
     * Production safety net: checks the next SWEEP_BUDGET watchers of the current round for a stream closed while
     * watched and moves it to the ready set, so its callbacks run late instead of never.
     */
    private function sweep(float $now): void
    {
        $this->nextSweep = $now + self::SWEEP_INTERVAL;

        if (!$this->watchers) {
            $this->sweepRound = [];
            $this->sweepPosition = 0;

            return;
        }

        if ($this->sweepPosition >= \count($this->sweepRound)) {
            $this->sweepRound = \array_keys($this->watchers);
            $this->sweepPosition = 0;
        }

        $end = \min($this->sweepPosition + self::SWEEP_BUDGET, \count($this->sweepRound));

        for ($position = $this->sweepPosition; $position < $end; ++$position) {
            $streamId = $this->sweepRound[$position];

            if (isset($this->watchers[$streamId]) && !$this->watchers[$streamId]->isActive()) {
                $this->markClosed($streamId);
            }
        }

        $this->sweepPosition = $end;
    }

    private function closedWhileWatched(int $streamId): \Error
    {
        $callbacks = [];

        foreach ($this->readCallbacks[$streamId] ?? [] as $callback) {
            $callbacks[] = \sprintf('"%s" (readable, %s)', $callback->id, ClosureHelper::getDescription($callback->closure));
        }

        foreach ($this->writeCallbacks[$streamId] ?? [] as $callback) {
            $callbacks[] = \sprintf('"%s" (writable, %s)', $callback->id, ClosureHelper::getDescription($callback->closure));
        }

        return new \Error(\sprintf(
            'Stream #%d was closed while its callbacks were still enabled: %s. Cancel a stream\'s callbacks before '
            .'closing the stream. This check runs because assertions are active or %s is set; without it the loop '
            .'finds such a stream late and treats it as ready.',
            $streamId,
            \implode(', ', $callbacks),
            self::DEBUG_ENV,
        ));
    }

    /**
     * @param float|null $timeout Seconds to wait, 0 for non-blocking, null for no limit.
     */
    private function wait(?float $timeout, ?Context $context): void
    {
        \assert(IdleSleep::isValidTimeout($timeout), 'Invalid wait timeout');

        if (!$this->watchers) {
            if ($this->selectStreams) {
                // stream_select() casts user-space wrapper streams (user code) right before its kernel wait, after
                // the last mayBlock() check; nothing can be checked in between, so the wait is always capped. The cap
                // covers a signal arriving just before the wait as well.
                $this->selectFallback($timeout === 0.0 ? 0.0 : IdleSleep::capped($timeout));

                return;
            }

            // Nothing to poll: sleep like StreamSelectDriver, until the next timer or, with only signal callbacks
            // enabled, until a signal arrives. An empty poll(2) context returns at once on a null timeout, so the
            // backend is not used for this.
            // Only a sleep with signal callbacks dispatches them, and only then can a handler change the loop's state.
            IdleSleep::sleep(
                $timeout,
                \array_keys($this->signalCallbacks),
                $this->signalQueue,
                $this->signalCallbacks ? $this->mayBlock(...) : null,
            );

            return;
        }

        if ($this->selectStreams && ($timeout === null || $timeout > self::FALLBACK_INTERVAL)) {
            $timeout = self::FALLBACK_INTERVAL;
        } elseif ($timeout === null || $timeout > IdleSleep::SIGNAL_WAIT_CAP) {
            // A signal arriving just before the kernel wait would not interrupt it, and a handler run asynchronously
            // in that window acts after the loop decided to block: bound the delay. Checked inline, so a
            // zero-timeout tick pays no call.
            $timeout = IdleSleep::forSignals($timeout, $this->signalCallbacks !== []);
        }

        // Every wait on the Context checks the process first: in a forked child the epoll descriptor is shared, and a
        // wait there can return events the parent registered after the fork, whose data lives in the parent's memory.
        $context ??= $this->ownContext();

        try {
            $ready = $context->wait(match (true) {
                $timeout === null => null,
                $timeout === 0.0 => $this->zero,
                default => Duration::fromNanoseconds((int) \ceil($timeout * 1_000_000_000)),
            });
        } catch (FailedPollWaitException $exception) {
            if ($exception->getCode() !== FailedPollWaitException::ERROR_INTERRUPTED) {
                throw $exception;
            }

            $ready = []; // Interrupted by a signal; it is dispatched at the start of the next tick.
        }

        if ($ready) {
            // Readers first, then writers, matching the order StreamSelectDriver enqueues callbacks in.
            $writable = [];

            foreach ($ready as $watcher) {
                $streamId = $watcher->getData();
                \assert(\is_int($streamId));
                $read = $write = false;

                foreach ($watcher->getTriggeredEvents() as $event) {
                    if ($event === Event::Write) {
                        $write = true;
                    } elseif ($event === Event::Read || $event === Event::ReadHangUp) {
                        $read = true;
                    } elseif ($event === Event::HangUp || $event === Event::Error) {
                        $read = $write = true;
                    }
                }

                if ($read && isset($this->readCallbacks[$streamId])) {
                    foreach ($this->readCallbacks[$streamId] as $callback) {
                        $this->enqueueCallback($callback);
                    }
                }

                if ($write && isset($this->writeCallbacks[$streamId])) {
                    $writable[] = $streamId;
                }
            }

            foreach ($writable as $streamId) {
                foreach ($this->writeCallbacks[$streamId] as $callback) {
                    $this->enqueueCallback($callback);
                }
            }
        }

        if ($this->selectStreams) {
            $this->selectFallback(0.0);
        }
    }

    /**
     * Checks the select-checked fallback streams.
     *
     * @param float|null $timeout Seconds to wait, 0 for non-blocking, null for no limit.
     */
    private function selectFallback(?float $timeout): void
    {
        $read = [];
        $write = [];

        foreach ($this->selectStreams as $streamId => $stream) {
            if (!\is_resource($stream)) {
                // stream_select() silently skips closed streams; treat them as ready like closed watched streams.
                $this->markClosed($streamId);

                continue;
            }

            if (isset($this->readCallbacks[$streamId])) {
                $read[$streamId] = $stream;
            }

            if (isset($this->writeCallbacks[$streamId])) {
                $write[$streamId] = $stream;
            }
        }

        if ($timeout !== null) {
            $seconds = (int) $timeout;
            $microseconds = (int) (($timeout - $seconds) * 1_000_000);
        } else {
            $seconds = null;
            $microseconds = null;
        }

        if (!$read && !$write) {
            return; // Only closed streams were left; they are dispatched through the ready set.
        }

        $except = null;

        \set_error_handler($this->streamSelectErrorHandler);

        try {
            $result = \stream_select($read, $write, $except, $seconds, $microseconds);
        } catch (\ValueError $exception) {
            // stream_select() dropped every stream it could not represent (php://memory). StreamSelectDriver selects
            // all watched streams in one call, so with other streams in the loop it surfaces the warning turned
            // exception instead; mirror that, and keep the ValueError when there is nothing else.
            if ($this->watchers && $exception->getPrevious() instanceof \Exception) {
                throw $exception->getPrevious();
            }

            throw $exception;
        } finally {
            \restore_error_handler();
        }

        if ($this->streamSelectIgnoreResult || $result === 0) {
            $this->streamSelectIgnoreResult = false;

            return;
        }

        if (!$result) {
            throw new \Exception('Unknown error during stream_select'); // As StreamSelectDriver.
        }

        foreach ($read as $stream) {
            foreach ($this->readCallbacks[(int) $stream] ?? [] as $callback) {
                $this->enqueueCallback($callback);
            }
        }

        foreach ($write as $stream) {
            foreach ($this->writeCallbacks[(int) $stream] ?? [] as $callback) {
                $this->enqueueCallback($callback);
            }
        }
    }

    private function dispatchReadyStreams(): void
    {
        foreach ($this->readyStreams as $streamId => $_) {
            foreach ($this->readCallbacks[$streamId] ?? [] as $callback) {
                $this->enqueueCallback($callback);
            }
        }

        foreach ($this->readyStreams as $streamId => $_) {
            foreach ($this->writeCallbacks[$streamId] ?? [] as $callback) {
                $this->enqueueCallback($callback);
            }
        }
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

    /**
     * The Context, opened on first use, provided this process created the driver and it was not abandoned.
     *
     * @throws LoopStateException In a forked child, or after the driver was abandoned.
     */
    private function ownContext(): Context
    {
        if (\getmypid() !== $this->creatorPid) {
            $this->releaseContext(); // Never again touch the descriptor from this process.

            throw $this->unusable();
        }

        return $this->context ?? $this->openContext();
    }

    /**
     * @throws LoopStateException After the driver was abandoned.
     */
    private function openContext(): Context
    {
        if ($this->abandoned) {
            throw $this->unusable();
        }

        $context = new Context($this->backend);
        $this->context = $context;
        $this->rejectCharacterDevices = \PHP_OS_FAMILY === 'Darwin' && $context->getBackend() === Backend::Poll;

        self::$withContext ??= new \WeakMap();
        self::$withContext[$this] = true;

        if (!self::$exitHookRegistered) {
            self::$exitHookRegistered = true;
            \register_shutdown_function(self::releaseInheritedContexts(...));
        }

        return $context;
    }

    private function unusable(): LoopStateException
    {
        if (\getmypid() !== $this->creatorPid) {
            return new LoopStateException(\sprintf(
                'This PollDriver was created in process %d and is used in forked process %d, where its Io\\Poll '
                .'descriptor is shared with or lost from the parent. Call Fledge\\Loop::afterFork() in the child '
                .'right after pcntl_fork() (or use Fledge\\Loop::fork()) to start a fresh event loop.',
                $this->creatorPid,
                \getmypid(),
            ));
        }

        return new LoopStateException(
            'This PollDriver was abandoned (Fledge\\Loop::afterFork() or Fledge\\Loop::install() replaced it) and can '
            .'no longer be used; use the active driver (Fledge\\Loop::getDriver()).',
        );
    }

    private function handleSignal(int $signal): void
    {
        // Queue signals, so we don't suspend inside pcntl_signal_dispatch, which disables signals while it runs.
        $this->signalQueue->enqueue($signal);
    }
}
