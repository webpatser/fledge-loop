# Fledge Loop

An event loop for PHP 8.6 fibers: timers, streams, signals and suspensions behind one static facade.

[![Tests](https://github.com/webpatser/fledge-loop/actions/workflows/tests.yml/badge.svg)](https://github.com/webpatser/fledge-loop/actions/workflows/tests.yml) [![Packagist Version](https://img.shields.io/packagist/v/webpatser/fledge-loop)](https://packagist.org/packages/webpatser/fledge-loop) [![PHP Version](https://img.shields.io/packagist/php-v/webpatser/fledge-loop)](https://packagist.org/packages/webpatser/fledge-loop) [![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

## What it is

Fledge Loop runs fibers on an event loop. One static facade, `Fledge\Loop`, gives you deferred callbacks, delays and
repeating timers, stream readiness, signals and suspensions (the await pattern for fibers). It runs on PHP 8.6's
`Io\Poll` (epoll on Linux, kqueue on macOS and BSD), with a `stream_select()` driver as the fallback and as a rollback
switch. It is safe to fork. It is part of the Fledge family, but it stands on its own and needs nothing beyond PHP.

- [Install](#install)
- [Quick start](#quick-start)
- [Choosing a driver](#choosing-a-driver)
- [Forking](#forking)
- [Closing streams](#closing-streams)
- [Signals](#signals)
- [Fiber I/O waiter](#fiber-io-waiter)
- [Performance](#performance)
- [Testing](#testing)
- [API notes](#api-notes)
- [PHP version policy](#php-version-policy)
- [Status](#status)
- [Origins](#origins)
- [License](#license)

## Install

```bash
composer require webpatser/fledge-loop
```

Requires PHP 8.6. Optional: `ext-pcntl` for signal callbacks (`Loop::onSignal()`) and `Loop::fork()`, and
[`ext-fiberio`](https://github.com/webpatser/php-fiberio) for the `FiberIoWaiter`.

Fledge Loop conflicts with `revolt/event-loop` on purpose: one loop per process. Coming from Revolt, rename
`Revolt\EventLoop` to `Fledge\Loop` and `Revolt\EventLoop\X` to `Fledge\Loop\X`.

## Quick start

Callbacks run in their own fibers and must return nothing. `Loop::run()` returns when no enabled, referenced callback
is left.

Deferred, delayed and repeating callbacks:

```php
use Fledge\Loop;

Loop::defer(function () {
    echo "deferred\n";
});

Loop::delay(0.12, function () {
    echo "after 120 ms\n";
});

$n = 0;
Loop::repeat(0.05, function (string $id) use (&$n) {
    echo 'tick ', ++$n, "\n";
    if ($n === 3) {
        Loop::cancel($id);
    }
});

Loop::run(); // returns when nothing referenced is left
```

```
deferred
tick 1
tick 2
after 120 ms
tick 3
```

Waiting for a readable stream:

```php
use Fledge\Loop;

[$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
stream_set_blocking($reader, false);

Loop::onReadable($reader, function (string $id, $stream) {
    echo 'got: ', fread($stream, 1024), "\n";
    Loop::cancel($id); // cancel before you close
    fclose($stream);
});

Loop::delay(0.05, function () use ($writer) {
    fwrite($writer, 'hello');
});

Loop::run();
```

```
got: hello
```

A suspension, the await pattern. `suspend()` parks the calling fiber while the loop keeps running; `resume()` wakes
it with a value:

```php
use Fledge\Loop;

function fetch(string $what): string
{
    $suspension = Loop::getSuspension();
    Loop::delay(0.05, fn () => $suspension->resume("result of $what"));

    return $suspension->suspend();
}

Loop::queue(function () {
    echo fetch('A'), "\n";
});
Loop::queue(function () {
    echo fetch('B'), "\n";
});

Loop::run();
```

```
result of A
result of B
```

A signal handler (needs `ext-pcntl`):

```php
use Fledge\Loop;

Loop::onSignal(SIGUSR1, function (string $id) {
    echo "got SIGUSR1\n";
    Loop::cancel($id); // the last callback gone: the default disposition is back
});

Loop::delay(0.05, function () {
    posix_kill(getmypid(), SIGUSR1);
});

Loop::run();
```

```
got SIGUSR1
```

## Choosing a driver

The loop picks its driver on first use. `FLEDGE_EVENT_LOOP` selects it (case and surrounding whitespace are ignored):

| Value | Driver |
|---|---|
| `auto` (default, also when unset or empty) | `PollDriver` on kqueue (macOS, BSD) or epoll (Linux); `StreamSelectDriver` where neither exists |
| `poll` | `PollDriver`; throws when neither kqueue nor epoll is available |
| `select` | `StreamSelectDriver`, the rollback (limited to descriptors below FD_SETSIZE, usually 1024) |

Any other value throws a `Fledge\Loop\UnknownDriverException` (an `InvalidArgumentException`) at the loop's first use. To choose in code, call `Fledge\Loop::install('poll')` (or without an argument to read the variable) during the process's own setup, before the loop has run anything; it refuses while the loop is running or has callbacks registered, with a `Fledge\Loop\LoopStateException` (a `LogicException`). Catch `LoopStateException` rather than `LogicException` to keep a refusal apart from an unknown name, since `InvalidArgumentException` extends `LogicException`. `PollDriver` opens its kernel descriptor at the first stream watcher, so a process that only uses timers, signals and suspensions holds none.

To find out which driver the loop has without creating one, call `Fledge\Loop::hasDriver()` (true once a driver was created, installed or set) and `Fledge\Loop::driverName()` (`poll`, `select`, the class name of a third-party driver, or null when there is none yet). Both are safe to call before `install()`; `Fledge\Loop::getDriver()` creates the default driver as a side effect.

Pick `auto` unless you have a reason not to. Pick `select` to roll back to the plain `stream_select()` loop. `select` cannot watch descriptors at or above FD_SETSIZE (usually 1024);
it throws when asked to.

## Forking

A forked child must not keep the parent's loop: an epoll descriptor is shared with the parent after `fork()` (the child would change the parent's registrations), and a kqueue descriptor is not inherited at all. Start the child on a fresh loop with one of:

```php
$pid = Fledge\Loop::fork();          // pcntl_fork() plus afterFork() in the child

// or, around your own pcntl_fork():
$pid = pcntl_fork();
if ($pid === 0) {
    Fledge\Loop::afterFork();        // first thing in the child, from {main}
}
```

What the child gets:

- An empty loop. The parent's timers, stream watchers, deferred callbacks and loop signal handlers are gone (the signal handlers are reset to SIG_DFL); register the child's own afterwards.
- Callback ids from the parent are dead: the child's ids continue the parent's sequence, so a stale id is unknown instead of naming one of the child's callbacks.
- Objects built in the parent that rely on their own loop callbacks (servers, pools, timeout queues, heartbeats) do not work in the child. Build them again there.

Fork from {main}, never from a loop callback or a fiber: `fork()` and `afterFork()` throw a `LoopStateException` (a `LogicException`) there, and a raw `pcntl_fork()` inside a callback leaves a child that can never call `afterFork()` (it is itself inside the loop's fiber). A master that decides to fork inside the loop suspends {main} and forks once it has resumed:

```php
$workers = 0;
while ($workers < $wanted) {
    $suspension = Fledge\Loop::getSuspension();
    Fledge\Loop::delay(0.5, $suspension->resume(...));   // or resume from a signal, timer or socket callback
    $suspension->suspend();                              // back in {main} here
    if (Fledge\Loop::fork() === 0) {
        runWorker();
        exit(0);
    }
    $workers++;
}
```

Fork from a quiet point. Fibers suspended in the parent unwind in the child during `afterFork()`, and their `finally` blocks can write to the parent's peers. If one of them throws (or tries to suspend), `afterFork()` still installs the fresh driver and lets every other fiber unwind, then throws a `Fledge\Loop\ForkUnwindException` (a `RuntimeException`) with the first error as previous and every error it caught in `getUnwindErrors()`; `fork()` logs such errors and returns 0 as usual.

`afterFork()` only works in a forked child: in a process whose driver it created itself (it never forked, or `afterFork()` already ran in this child) it throws a `LoopStateException` and keeps every callback. In a child of a child it works again.

If `afterFork()` fails inside `fork()`'s child (an inherited `Io\Poll\Context` still referenced, an unusable `FLEDGE_EVENT_LOOP`), the child logs the error and calls `exit(255)`. That exit runs the shutdown functions the child inherited from the parent, for example a master's pid-file cleanup; guard such functions with a `getmypid()` check.

`afterFork()` is the only full protection. Without it the poll driver throws a `LoopStateException` as soon as the child uses the inherited descriptor, and an exit hook keeps a plain child exit from disturbing the parent; that hook is best effort. `afterFork()` refuses a third-party `Driver` implementation, which it cannot discard without calling into it.

## Closing streams

Cancel a stream's callbacks before closing the stream. Closing a stream while one of its callbacks is enabled is a programming error: kqueue and epoll drop a closed descriptor without an event, so nothing would wake its callbacks.

"Closing" includes the cases where PHP closes a stream you still hold:

- `proc_close($process)`, and dropping the last reference to a `proc_open()` process handle, close that process's pipes. Cancel the callbacks on a child's pipes before `proc_close()` or before letting the process handle go.
- `socket_close()` on an ext-sockets `Socket` closes the stream it was imported from (`socket_import_stream()`) or exported to (`socket_export_stream()`).
- `fclose()` and `pclose()`, of course.

`stream_socket_shutdown()`, `proc_terminate()`, dropping a `Socket` object (the stream keeps it alive) and dropping a curl handle that writes to a stream do not close the stream (checked on PHP 8.6).

In debug mode the error is thrown at the next tick, out of `run()` (or out of the `suspend()` that is running the loop); it is not passed to the loop's error handler. Its message names every callback on the stream and where its closure is defined. `FLEDGE_DEBUG` is read once, when the driver is created: a `putenv()` afterwards has no effect on that driver.

| | `PollDriver` | `StreamSelectDriver` |
|---|---|---|
| Debug mode: assertions active (`zend.assertions=1`) or `FLEDGE_DEBUG` truthy | Checks every watched stream before each wait and throws an `\Error` out of `run()` that names the callbacks and where their closures are defined | Throws an `\Error` ("ensure all callbacks on closed stream resources are cancelled") out of `run()` at the next tick |
| Production | No per-tick check. A safety net sweeps at most 4,096 watchers, round-robin, at most once per second, and treats a stream it finds closed as ready, so its callbacks run late instead of never. With 20,000 watched streams one is found within about 6 seconds (about 10 for a stream first watched while a round was under way) | Same as debug mode |

Idle behaviour: in production, while at least one stream is watched, the safety net needs its turn, so a loop with no timers and no signal callbacks wakes once per second instead of sleeping until a stream is ready (one wake per second; measured at N = 10,000 idle connections on Linux it costs about 1 to 2 ms of CPU per wake, mostly `Io\Poll`'s own wait cost). A loop that watches no stream, and every loop in debug mode, does not wake for the sweep. Independently of it, `StreamSelectDriver` caps every blocking `stream_select()` at one second, and with async signal dispatch on or a loop signal callback enabled every loop wakes at least once per second (see Signals).

## Signals

The recommended way to handle a signal is `Fledge\Loop::onSignal()`: the loop dispatches it, and a signal that arrives while the loop waits ends that wait.

A signal callback is installed with the kernel at the loop tick after `onSignal()` returns, not at once, and cancelling (or disabling) the last callback for a signal restores its default disposition. A process that must not die of an early SIGTERM should let the loop run a tick before it advertises readiness (for example before it writes its pid file or reports to a supervisor).

Plain `pcntl_signal()` handlers with async dispatch on (`pcntl_async_signals(true)`) work too, for example a SIGTERM handler that defers `stop()` onto the loop, but their effects are only guaranteed to be noticed within one second. Such a handler can run after the loop decided to block and right before the kernel wait starts, and PHP 8.6 has no atomic way to close that window (no `ppoll()`/`epoll_pwait()` signal mask on `Io\Poll`, no `signalfd`). So while async dispatch is on, and while loop signal callbacks are enabled, no blocking wait of either driver lasts longer than one second (`IdleSleep::SIGNAL_WAIT_CAP`), and the loop then acts on what the handler did. The cost is one wake per second for an idle process (see the benchmark README for the measured CPU). Without either, a plain handler's effects wait for the loop's next wake: the next timer, stream event or loop signal.

`StreamSelectDriver` caps every blocking `stream_select()` at the same second, and `PollDriver` caps a blocking `stream_select()` over its fallback set (user-space wrapper streams among others) there too: `stream_select()` calls a user-space wrapper's `stream_cast()` inside the wait call, after the loop's last look at its state, so a `stop()` or a new timer from there takes effect within one second.

## Fiber I/O waiter

`Fledge\Loop\FiberIoWaiter` is the waiter for [php-fiberio](https://github.com/webpatser/php-fiberio)'s hooked I/O.
After `\FiberIo\enable(new FiberIoWaiter)`, a stream wait or sleep that php-fiberio hooks inside a fiber parks that fiber on
the loop instead of blocking the process. It never suspends the loop's own fiber. It needs `ext-fiberio`; nothing else in
the package does.

## Performance

`bench/eventloop/` holds the scripts and the method; its README has the full tables for Linux and macOS. The numbers
below are from the same-day, same-mode comparison there (production mode, Linux container) of Fledge Loop's
`PollDriver` against revolt/event-loop v1.1.0's own `Io\Poll` driver:

- Throughput (round trips per second) is on par: equal at 10 and 400 idle connections, 2 to 3% ahead at 1,000 and 10,000.
- Toggling a callback's stream interest costs about 40% of the original's.
- A tick with many idle streams costs a fifth to a sixth.
- Idle CPU with 10,000 idle connections is about 15% lower.
- A steady callback is about 17% dearer, mostly the fork guard and the stop re-check.

## Testing

The suite runs with assertions enabled and throwing, as the drivers assert their invariants. `zend.assertions` can only be switched between on and off at runtime: when php.ini compiles assertions out (`-1`, the `php.ini-production` default), only a command line flag or an ini file enables them, and `phpunit.xml` cannot. A guard test fails when they are off.

```bash
composer test                                   # php -d zend.assertions=1 -d assert.exception=1 vendor/bin/pest
FLEDGE_EVENT_LOOP=select composer test          # the same on StreamSelectDriver
composer analyse                                # PHPStan

# Linux (epoll) in the php:8.6-cli container, whose ini enables assertions too
docker compose --profile linux run --rm php86 php -d zend.assertions=1 -d assert.exception=1 vendor/bin/pest
```

CI runs the suite on Ubuntu and macOS with `FLEDGE_EVENT_LOOP` unset (the default selection), `poll` and `select`.

## API notes

Exceptions the loop throws, all in `Fledge\Loop`:

| Class | Extends | Thrown by |
|---|---|---|
| `LoopStateException` | `LogicException` | `install()`, `afterFork()` and `fork()` refusing in the current state; a `PollDriver` used in a forked child or after it was replaced |
| `UnknownDriverException` | `InvalidArgumentException` | a driver name other than `auto`, `poll` or `select` (argument or `FLEDGE_EVENT_LOOP`) |
| `UnsupportedFeatureException` | `Exception` | `poll` requested where neither kqueue nor epoll exists; `onSignal()` and `fork()` without ext-pcntl; a signal handler that cannot be installed |
| `ForkFailedException` | `RuntimeException` | `fork()` when `pcntl_fork()` fails; no child was created |
| `ForkUnwindException` | `RuntimeException` | `afterFork()` when fibers inherited from the parent threw while unwinding; the fresh driver is installed |
| `InvalidCallbackError` | `Error` | an unknown callback id passed to `enable()`, `reference()` and the getters; a callback that returns a value |
| `UncaughtThrowable` | `Error` | `run()` (or the `suspend()` running the loop), wrapping an exception a callback threw with no error handler set |

Other throws, kept as the original loop has them:

- `delay()` and `repeat()` throw an `\Error` for a negative or NAN duration. `INF` is accepted: a timer that never fires.
- `run()` inside a fiber or while the loop runs, misuse of a `Suspension`, and a stream closed while watched (see Closing streams) throw an `\Error`.
- A `stream_select()` failure throws `\Exception`: in `StreamSelectDriver` for every watched stream (including the FD_SETSIZE limit, whose message points to the poll driver), in `PollDriver` for its fallback set. The message is PHP's warning, or "Unknown error during stream_select".
- `FiberIoWaiter` throws an `\InvalidArgumentException` for a NAN timeout or a stream wait without a stream.

## PHP version policy

This package supports only the latest stable PHP release. When a new PHP version is released, the next release of this package requires it and support for the previous PHP version ends. Currently: PHP 8.6.

## Status

Version 0.1.0, the first release. The API may still change before 1.0; such changes are listed in the [CHANGELOG](CHANGELOG.md). Fledge Loop is the event loop of the Fledge stack: fledge-fiber, the Fledge framework (fledge-framework), the Torque queue worker and the Resonate WebSocket server run on it.

## Origins

Fledge Loop is derived from [revolt/event-loop](https://github.com/revoltphp/event-loop) v1.1.0 (MIT), which grew out of amphp's loop. It is an independent fork for PHP 8.6: the same fibers, suspensions and callbacks, reshaped and optimised for Fledge and maintained on its own. Credit for the original design goes to the [Revolt](https://revolt.run) and [amphp](https://amphp.org) projects; see [LICENSE](LICENSE) and [ORIGINS.md](ORIGINS.md).

## License

MIT, see [LICENSE](LICENSE).
