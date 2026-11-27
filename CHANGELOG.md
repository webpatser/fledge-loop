# Changelog

All notable changes to this project are listed here, in the style of [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 0.1.2 - 2026-11-27

### Added

- **`Durations::toWholeSeconds()`.** Whole seconds as an int, consistent with the other `to*()` methods: rounded up (towards positive infinity), the sign of a negated `Duration` kept (-1.5 seconds is -1), INF and out-of-range floats saturated to `PHP_INT_MAX`/`PHP_INT_MIN`, NAN rejected with the existing `\Error`, int input passed through. A `Duration` is converted with integer maths, so a nanosecond fraction always adds a second, also above about 1e8 seconds where a float `ceil()` loses it. It replaces the hand-rolled `(int) \ceil(Durations::toSeconds($x))`; clamping to zero stays with the caller.

## 0.1.1 - 2026-11-27

### Added

- **`Durations` helper.** `Fledge\Loop\Durations` converts between PHP 8.6's `Time\Duration` and the loop's float seconds: `fromSeconds()` (INF or beyond the `Duration` range of 9,223,372,035.999999999 seconds is null, meaning no limit; NAN and negative values throw the loop's delay `\Error`), `toSeconds()`, `toMilliseconds()`, `toMicroseconds()`, `toNanoseconds()` and `nullable()` (null stays "no timeout"). Conversions from float always round up, so nothing waits shorter than asked; the `to*()` methods keep the sign of a negated `Duration` and saturate INF to `PHP_INT_MAX`.
- **`Duration` timers.** `Loop::delay()`, `Loop::repeat()` and `Driver::delay()`/`Driver::repeat()` take `Time\Duration|float`. A `Duration` is converted to float seconds once, on entry; timers, the timer queue and the clock stay float, and float input behaves exactly as before. A negated `Duration` throws the same `\Error` as a negative delay or interval. A timer that never fires is still float `INF`, since a `Duration` has no infinity. A third-party `Driver` implementation widens both parameters to `Duration|float` (keeping `float` is a fatal error when the class loads).

## 0.1.0 - 2026-11-22

First release. Fledge Loop is the event loop for Fledge on PHP 8.6: fibers, suspensions, timers, signals and non-blocking stream I/O behind the `Fledge\Loop` facade, with a driver on PHP 8.6's `Io\Poll` (kqueue, epoll). It is derived from revolt/event-loop v1.1.0 and keeps its callback model and public API under the `Fledge\Loop` namespace (`Fledge\Loop` for `Revolt\EventLoop`, `Fledge\Loop\*` for `Revolt\EventLoop\*`); see [ORIGINS.md](ORIGINS.md).

`composer.json` conflicts with `revolt/event-loop`, so the two loops are never installed side by side.

The entries below are the differences from revolt/event-loop v1.1.0.

### Added

- **Drivers.** `PollDriver` on `Io\Poll` (kqueue on macOS and BSD, epoll on Linux; no FD_SETSIZE limit) next to `StreamSelectDriver`.
- **Driver selection.** `FLEDGE_EVENT_LOOP` (`auto`, `poll`, `select`; anything else throws `UnknownDriverException` at the loop's first use) is honoured by the lazily created default driver. `Loop::install()` picks a driver during a process's own setup and refuses while the loop is running or has callbacks registered.
- **Driver introspection.** `Loop::hasDriver()` and `Loop::driverName()` report the driver without creating one; `driverName()` returns null while the loop swaps drivers. `Loop::inLoopFiber()` tells whether the caller runs in the loop's own fiber.
- **Forking.** `Loop::afterFork()` discards the driver a forked child inherited, with no kernel operation on the parent's descriptor, and installs a fresh empty loop whose callback ids continue the parent's sequence (a parent id is unknown in the child). `Loop::fork()` is `pcntl_fork()` plus `afterFork()` in the child.
- **Fork guard.** `PollDriver` refuses to sync or wait on its descriptor in any process but its creator, and an exit hook keeps a child that exits without `afterFork()` from deleting the parent's epoll registrations.
- **Lazy kernel descriptor.** `PollDriver` opens its kqueue or epoll descriptor at the first stream watcher (or `getHandle()` call), so a process that only uses timers, signals and suspensions holds none.
- **`FiberIoWaiter`.** The waiter for php-fiberio's hooked I/O (`\FiberIo\enable(new FiberIoWaiter)`), which parks the calling fiber on the loop and never suspends the loop's own fiber.
- **Dedicated exceptions.** `LoopStateException` (a `LogicException`) for refused loop states and a `PollDriver` used in a forked child or after it was replaced; `UnknownDriverException` (an `InvalidArgumentException`) for a driver name other than `auto`, `poll` or `select`; `ForkFailedException` (a `RuntimeException`) when `pcntl_fork()` fails; `ForkUnwindException` (a `RuntimeException`) when fibers inherited from the parent threw while unwinding in `afterFork()`. The README lists every exception the loop throws.
- **Debug switch.** Assertions active (`zend.assertions=1`) or a truthy `FLEDGE_DEBUG` turn on the closed-stream check described under Changed.
- **Tests run with assertions on.** The suite (revolt/event-loop's v1.1.0 tests, ported, plus Fledge's own) runs with `zend.assertions=1` and `assert.exception=1` on macOS and Linux, with `FLEDGE_EVENT_LOOP` unset, `poll` and `select`; a guard test fails when assertions are off.

### Changed

- **PHP 8.6 only.** No branches for older PHP versions.
- **Cancel before close.** Closing a stream while one of its callbacks is enabled is a programming error. In debug mode (assertions active, or `FLEDGE_DEBUG` truthy) `PollDriver` checks every watched stream before each wait and throws an `\Error` naming the callbacks and where their closures are defined. In production a safety net checks at most 4,096 watchers once per second, round-robin, and runs a closed stream's callbacks as if it were ready (with 20,000 watched streams within about 6 seconds); while streams are watched, a production loop therefore wakes once per second. `StreamSelectDriver` throws at the next tick in every mode.
- **Regular files are always ready.** `PollDriver` reports regular files (and streams `stream_select()` reads as regular files, such as `php://temp`) ready for read and write on every backend, as `stream_select()` does. User-space wrapper streams are checked with `stream_select()` every tick instead of trusting their `stream_stat()`.
- **Waits are bounded while signals can arrive.** While a loop signal callback is enabled, and while async signal dispatch is on (`pcntl_async_signals(true)`), every blocking wait of both drivers is capped at one second (`IdleSleep::SIGNAL_WAIT_CAP`): a signal or async handler that lands just before the kernel wait is acted on at most that late. Every blocking `stream_select()` is capped there as well, because it runs user-space wrappers' `stream_cast()` inside the wait call.
- **Signal callbacks.** A signal callback is installed at the tick after `onSignal()`, and cancelling the last one for a signal restores its default disposition. On Linux, a loop with only signal callbacks waits for its signals race-free with `pcntl_sigtimedwait()`.
- **NAN delays are rejected.** `delay()` and `repeat()` reject NAN (it would corrupt the timer heap's order) with the same `\Error` as a negative value; `-0.0` counts as zero. A timer at `INF` never fires and never spins the loop.
- **O(1) bookkeeping.** "Anything left to run" is a counter of enabled and referenced callbacks (verified against a full scan on every tick with assertions on) instead of a scan of all callbacks twice per tick. The microtask and callback queues are arrays with head and tail positions instead of `SplQueue`s. `PollDriver` applies interest changes once per tick, right before the wait, and reads a watcher's triggered events in one pass.

### Removed

- The Ev, Event, Uv, Tracing and IoPoll drivers.
- `REVOLT_DRIVER` as the way to select a driver; `FLEDGE_EVENT_LOOP` replaces it.

### Fixed

- **Bounded waits.** Every blocking wait is at most `UINT32_MAX` microseconds (about 71.6 minutes): a loop with only signal callbacks, or a timer further away than that, sleeps in steps instead of throwing a `ValueError` from `usleep()`. "No limit" is an explicit null timeout, so `INF` never reaches an int conversion.
- **No wait on a stale decision.** A tick decides whether its wait may block before user code runs in it. Both drivers recheck the stop flag, the count of enabled and referenced callbacks and all queued work right before they compute the wait timeout, after the tick's callbacks and the signal handlers `pcntl_signal_dispatch()` ran: a `stop()`, a cancelled last callback or a new timer from that code takes effect at once.
- **Callbacks changed mid-tick.** A callback that an async signal handler enables or defers during a tick is kept for the next one. One that such a handler (or user code reached from a driver call) disables, re-enables or cancels while the tick holds it is active in the driver at most once: whatever the tick activated for it is undone when it was disabled or queued again meanwhile, and a re-enabled one is activated by the next tick. Inserting a timer that is already in the timer heap replaces its entry.

### Performance

- Throughput is on par with revolt/event-loop v1.1.0's `Io\Poll` driver in a same-day, same-mode comparison; an interest toggle costs about 40% of the original's, a tick with many idle stream objects a fifth to a sixth, and idle CPU at 10,000 idle connections is about 15% lower. A steady callback is about 17% dearer. See `bench/eventloop/README.md`.
