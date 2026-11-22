# Event loop driver benchmarks

Three scripts measure what the two drivers cost: `PollDriver` (PHP 8.6 `Io\Poll`: kqueue on macOS, epoll on Linux,
or poll(2)) and `StreamSelectDriver`. They are the yardstick for the optimisation work on the loop.

| Script | Measures |
|---|---|
| `idle_connections.php` | Throughput, latency and CPU with N watched but idle TCP connections next to 8 ping-pong pairs, then the CPU of a 10 s idle phase |
| `callback_churn.php` | Cost per callback of a steady writable callback, and of one that disables and enables itself every call (per toggle = churn minus steady) |
| `tick.php` | Cost of one zero-timeout loop tick, with W idle watchers (`--watchers`) or with K idle stream objects (`--streams`: readable enabled plus writable registered but disabled, as stream classes keep them) |

## Running them

Run benchmarks alone: no test suite or other benchmark at the same time (check with
`pgrep -f 'php .*(pest|idle_connections|tick\.php|callback_churn)'`). Every script starts its child processes with
`zend.assertions=-1` (production mode, `assert()` compiled away); `--assertions=1` measures with assertions on.

```bash
# macOS (or any host with PHP 8.6)
php bench/eventloop/idle_connections.php --driver=select,poll --idle=10,400,1000,10000
php bench/eventloop/callback_churn.php --driver=select,poll
php bench/eventloop/tick.php --driver=select,poll --watchers=0,1
php bench/eventloop/tick.php --driver=poll --watchers= --streams=0,1,100,1000,10000 --runs=5

# poll driver on a specific Io\Poll backend
php bench/eventloop/idle_connections.php --driver=poll --backend=poll --idle=10,400

# Linux container (php:8.6-cli, see docker/php86-cli)
docker compose --profile linux run --rm php86 php bench/eventloop/idle_connections.php --driver=select,poll
docker compose --profile linux run --rm php86 php bench/eventloop/callback_churn.php
docker compose --profile linux run --rm php86 php bench/eventloop/tick.php
```

`idle_connections.php` options:

| Option | Default | Meaning |
|---|---|---|
| `--driver` | `select,poll` | Comma-separated drivers: `select`, `poll`, `auto` (anything `DriverFactory::create()` accepts) |
| `--backend` | `auto` | `Io\Poll` backend for `poll`: `auto`, `poll`, `epoll`, `kqueue` |
| `--idle` | `10,400,1000,10000` | Comma-separated numbers of idle connections (N) |
| `--pairs` | `8` | Ping-pong pairs |
| `--seconds` | `10` | Ping-pong measurement window |
| `--warmup` | `1` | Ping-pong warm-up before sampling starts |
| `--idle-seconds` | `10` | Length of the pure idle phase |
| `--runs` | `3` | Runs per configuration (each a separate process) |
| `--max-spread` | `0.10` | A configuration whose round trip spread exceeds this is rerun once |
| `--signal` | off | Register an (unreferenced) loop signal callback, so every blocking wait is capped at `IdleSleep::SIGNAL_WAIT_CAP` |
| `--json` | off | Print JSON (all runs and summaries) instead of the table |

One configuration (driver, backend, N) takes about 22 seconds per run, so the default matrix runs for several minutes.
Progress lines go to stderr, the table to stdout. `callback_churn.php` takes `--driver`, `--iterations` (100000) and
`--runs` (3, median of the processes with min and max); `tick.php` takes `--driver`, `--watchers`, `--streams`,
`--ticks` (300000; divided by K / 10 above K = 10), `--rounds` (7 per process) and `--runs` (1; median of the
processes' medians with min and max). All three take `--assertions` (-1).

## What idle_connections.php does

Every run is its own PHP process with one driver installed through `Fledge\Loop::setDriver()`. Only the loop API is
used (`onReadable`, `delay`, `repeat`), no stream classes, so the measurement is the driver and the loop core.

1. The open file soft limit is raised with `posix_setrlimit()` to what the configuration needs; the table footer
   prints the limit in effect.
2. The 8 ping-pong pairs are opened first, so their descriptors stay low, then N idle loopback TCP connections. Both
   ends of every connection live in the same process, and both ends carry a readable callback that never fires: N
   idle connections are 2N watched descriptors. All sockets are non-blocking, `TCP_NODELAY` and `SO_LINGER 0`, so
   process exit resets them instead of leaving thousands of `TIME_WAIT` entries between runs.
3. Ping-pong phase: each pair bounces one byte back and forth, every hop driven by `onReadable`. After the warm-up
   the script counts round trips, records every round trip's latency with `hrtime()`, and takes `getrusage()` user
   plus system time over the window.
4. Idle phase: the pairs are cancelled, and a 100 ms repeating timer runs next to the N idle connections for
   `--idle-seconds`. About 96 timer ticks happen in 10 seconds, and every one of them is a blocking loop tick.

`StreamSelectDriver` cannot watch a descriptor numbered at or above `FD_SETSIZE` (1024). With 2N + 16 descriptors
that limit is crossed between N = 400 and N = 1000; such a run is reported as `n/a (FD_SETSIZE)`.

| Column | Meaning |
|---|---|
| rt/s | Round trips per second over all 8 pairs; median of the runs, with min to max |
| p50 / p99 us | Round trip latency percentiles in microseconds (median of the per-run percentiles) |
| CPU% | User plus system CPU over the ping-pong window as percent of wall time (about 100%: the ping-pong saturates one core) |
| idle CPU | CPU seconds of the whole 10 s idle phase: what N idle connections cost while nothing happens |
| us/tick | CPU per idle-phase tick, from the first timer tick to the end |
| spread | (max - min) / median of rt/s; above `--max-spread` the configuration is rerun once and the lower-spread set kept |

## Reference: revolt/event-loop v1.1.0, measured 2026-11-21 with assertions on

Measured on 2026-11-21, before this package existed, in fledge-fiber on top of revolt/event-loop v1.1.0, with three
drivers: revolt's `StreamSelectDriver` (select), revolt v1.1.0's own `Io\Poll` driver `IoPollDriver` (Revolt IoPoll),
and the `PollDriver` this package's driver grew from, then running on revolt/event-loop (Fledge poll on Revolt). All
figures in this section were taken with assertions on (`zend.assertions=1`), so they compare those three drivers with
each other, not with the production-mode figures further down. The Linux container figures count, the macOS figures
are indicative.

Round trips per second, median (select / Fledge poll on Revolt / Revolt IoPoll):

| N | Linux | macOS |
|---:|---|---|
| 10 | 271k / 272k / 278k | 162k / 160k / 159k |
| 400 | 71k / 226k / 228k | 71k / 151k / 151k |
| 1000 | n/a / 174.5k / 175.1k | n/a / 133k / 132k |
| 10000 | n/a / 36.9k / 35.5k | n/a / 33.2k / 31.7k |

Idle CPU seconds per 10 s idle phase, Linux (select / Fledge poll on Revolt / Revolt IoPoll): N = 10 0.020 / 0.017 /
0.014; N = 400 0.139 / 0.027 / 0.026; N = 1000 n/a / 0.048 / 0.040; N = 10000 n/a / 0.361 / 0.302.

Per-callback cost from `callback_churn.php`, ns (select / Fledge poll on Revolt / Revolt IoPoll):

| | Linux | macOS |
|---|---|---|
| steady | 1072 / 1239 / 1040 | |
| with enable/disable churn | 1466 / 1872 / 2395 | |
| per toggle | 394 / 633 / 1355 | 333 / 451 / 1330 |

Reading: throughput was a tie between the two `Io\Poll` drivers at every N; Revolt's idled about 15 to 20% cheaper and
had a 19% cheaper steady callback on Linux; Fledge's was about 2x cheaper per interest toggle (batched sync).

## Fledge Loop 0.1.0, measured 2026-11-22

Measured on the 0.1.0 code, alone on the machine (no test suite or other benchmark running), production mode
(`zend.assertions=-1`), 5 runs (processes) per figure, median with the min to max of the runs where it matters.
Apple M5, macOS 27.2, PHP 8.6.0 NTS (1-minute load about 1.2 to 2.0 from unrelated background jobs); Linux in the
`php:8.6-cli` container on the same Mac (Docker Desktop VM, PHP 8.6.0 ZTS, load under 0.9). `PollDriver` (kqueue on
macOS, epoll on Linux) only. The `tick.php` rows count K idle stream objects (readable enabled, writable registered but
disabled).

| Metric | Linux, production, 2026-11-22 | macOS, production, 2026-11-22 |
|---|---:|---:|
| steady ns/call (`callback_churn.php`) | 1128 (1115-1190) | 853 (848-894) |
| churn ns/call | 1667 (1654-1789) | 1323 (1309-1391) |
| per toggle ns | 540 | 467 |
| tick ns, K = 0 | 987 | 762 |
| tick ns, K = 1 | 1310 | 11881 |
| tick ns, K = 1000 | 4849 (4777-4873) | 16676 (16479-17175) |
| tick ns, K = 10000 | 45370 (45100-49454) | 64005 (63804-67025) |
| idle CPU s per 10 s, N = 10000 | 0.190 | 0.187 |
| rt/s, N = 10 | 278.3k (277.9k-281.1k) | 168.0k (167.9k-168.4k) |
| rt/s, N = 400 | 231.8k (230.7k-233.1k) | 156.3k (155.8k-157.1k) |
| rt/s, N = 1000 | 177.2k (176.8k-178.7k) | 133.5k (129.9k-137.0k) |
| rt/s, N = 10000 | 36.6k (36.5k-36.9k) | 33.2k (32.4k-33.5k) |

About 125 ns of every tick with a watcher is the `getmypid()` fork guard (a real syscall in the container), and no
cheaper fork detection exists in PHP userland. On macOS the kqueue zero-timeout wait (about 11.4 us) dominates every
tick with a watcher.

### Against revolt/event-loop v1.1.0 in the same mode

Measured 2026-11-22 in the Linux container, both in production mode (`zend.assertions=-1`): these scripts in a scratch
project with `revolt/event-loop` 1.1.0 and its `IoPollDriver` (only the loop class and driver constructor swapped),
against Fledge Loop's `PollDriver` (epoll), 5 interleaved rounds of one process each, median with min to max.

| Metric | Fledge Loop `PollDriver` | revolt `IoPollDriver` |
|---|---:|---:|
| steady ns/call | 1194 (1096-1232) | 1017 (1007-1082) |
| churn ns/call | 1685 (1681-1694) | 2295 (2278-2366) |
| per toggle ns | 496 (462-585) | 1267 (1241-1359) |
| tick ns, no watcher | 991 (987-993) | 989 (984-1007) |
| tick ns, 1 idle watcher | 1304 (1295-1322) | 1222 (1220-1228) |
| tick ns, K = 1000 | 4822 (4765-4847) | 26354 (26316-26530) |
| tick ns, K = 10000 | 45289 (44873-46611) | 272911 (270357-275358) |
| idle CPU s per 10 s, N = 10000 | 0.224 (0.203-0.245) | 0.262 (0.239-0.267) |
| rt/s, N = 10 | 279.9k (278.1k-281.7k) | 278.5k (278.2k-279.3k) |
| rt/s, N = 400 | 230.1k (228.6k-232.6k) | 230.8k (230.6k-232.0k) |
| rt/s, N = 1000 | 178.3k (177.4k-179.8k) | 175.0k (173.9k-176.5k) |
| rt/s, N = 10000 | 36.2k (35.8k-36.3k) | 35.0k (34.8k-35.1k) |

Throughput is on par: equal at N = 10 and 400, and 2 to 3% ahead at N = 1000 and 10000. An interest toggle costs about
40% of revolt's, a tick with K idle stream objects a fifth to a sixth, and idle CPU at N = 10000 is about 15% lower.
The steady callback is about 180 ns (17%) dearer and a tick with one watcher about 80 ns, most of it the fork guard
above. Idle CPU at N up to 1000 is 0.002 to 0.003 s per 10 s higher than revolt's, with overlapping ranges.

The tick with K = 10,000 is bimodal in the container: most rounds land at about 45,300 ns, single rounds at 49,000 to
51,000 ns. Interleaved runs of three builds (6 rounds of 5 processes each) show the same pattern in all of them, so a
single sample at about 50,000 ns is that noise, not a regression.

Where the time goes: the O(1) count of enabled and referenced callbacks keeps a tick independent of the number of
registered callbacks; the cancel-before-close contract removes any per-tick closed-stream scan in production; the
array queues, one-pass event reading and one process check per tick keep the steady callback and the toggle cheap.
What remains is mostly `Io\Poll` itself: its `Context::wait()` costs about 5 ns per registered watcher even when
nothing is ready (zero-timeout wait with 10,000 idle watchers: 52 us on Linux, 66 us on macOS, measured 2026-11-21)
and about 1.9 ms of CPU per blocking wait with 20,000 watchers, whatever `maxEvents` is. That is the K-proportional
rest of the tick figures and most of the idle CPU at N = 10000; it is in php-src, not in this package.

### Cost of the one-second wait cap

While async signal dispatch is on (`pcntl_async_signals(true)`) or a loop signal callback is enabled, every blocking
wait ends within one second (see the Signals section of the main README), and `StreamSelectDriver` caps every blocking
`stream_select()` at one second in any case. Measured with an otherwise idle loop, Linux container, production mode,
CPU seconds over 10 s, median of 3 runs (async dispatch off / on):

| Loop | idle CPU per 10 s |
|---|---|
| `PollDriver`, 1 idle stream | 0.0021 / 0.0020 s |
| `PollDriver`, 10,000 idle streams | 0.0234 / 0.0223 s |
| `StreamSelectDriver`, 1 idle stream | 0.0018 / 0.0018 s |
| no stream, one timer an hour away (`PollDriver` / `StreamSelectDriver`) | 0.0008 / 0.0017 s and 0.0007 / 0.0017 s |

A production `PollDriver` that watches streams already wakes once per second for its closed-stream safety net, so the
cap adds nothing there. A loop without streams pays about 0.1 ms of CPU per second for the cap, and
`StreamSelectDriver` with a watched stream about 0.1 ms of CPU per second against an uncapped wait (0.0008 s per 10 s).
`pcntl_async_signals()` itself, asked once before a wait longer than the cap, costs about 2 ns on macOS and 5 ns in the
container.

## Limits of the method

- Single process: both ends of every connection, the client and the server work, share one core and one loop. A real
  server only holds the server half; client-side work inflates the per-tick costs compared with production.
- Loopback only: no network latency, no packet loss, no NIC interrupts. The figures are loop overhead, not network
  performance.
- The Linux numbers come from an arm64 container in Docker Desktop's VM on a Mac. It is not a production Linux box:
  kernel, CPU and scheduler differ, and absolute numbers will too. The relative ordering of drivers is the useful part.
- One-byte ping-pong exercises the dispatch path only; it says nothing about large writes or TLS.
- Latency samples are taken in PHP with `hrtime()` inside the callbacks, so they include callback scheduling in the
  loop, which is what an application sees.
- On macOS a zero-timeout `kevent()` that finds nothing ready costs about 11.5 us in the kernel (a 1 us timeout costs
  about 2.7 us, and a raw `kevent()` from Python shows the same), so `tick.php` with one idle watcher on kqueue measures
  the kernel, not the loop. epoll's zero-timeout wait costs about 0.2 us.
