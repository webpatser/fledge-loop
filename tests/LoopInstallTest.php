<?php

declare(strict_types=1);

use Fledge\Loop;
use Fledge\Loop\Driver\PollDriver;
use Fledge\Loop\Driver\StreamSelectDriver;
use Fledge\Loop\DriverFactory;
use Fledge\Loop\LoopStateException;
use Fledge\Loop\UnknownDriverException;

/*
 * DriverFactory never touches the process-wide driver, so those tests run in this process. Every test that calls
 * Loop::install() (or relies on the lazily created default driver) runs in a fresh PHP process instead: once any
 * earlier test in the suite has awaited from {main}, the loop keeps its fiber suspended and reports the global driver
 * as running until Loop::run() returns, so the suite cannot promise an idle loop here.
 *
 * Each test restores the FLEDGE_EVENT_LOOP environment variable afterwards.
 */
beforeEach(function () {
    $this->previousEnv = getenv(DriverFactory::ENV);
    putenv(DriverFactory::ENV);
});

afterEach(function () {
    $this->previousEnv === false
        ? putenv(DriverFactory::ENV)
        : putenv(DriverFactory::ENV.'='.$this->previousEnv);
});

/**
 * Run PHP code in a fresh process (autoloaded, FLEDGE_EVENT_LOOP unset unless $env sets it) and decode the JSON it
 * prints.
 *
 * @param  array<string, mixed>  $vars  Variables defined (via var_export) before the code runs.
 * @param  array<string, string>  $env  Extra environment variables.
 * @return array<string, mixed>
 */
function loopInFreshProcess(string $code, array $vars = [], array $env = []): array
{
    $prelude = '';

    foreach ($vars as $name => $value) {
        $prelude .= '$'.$name.' = '.var_export($value, true).";\n";
    }

    ['stdout' => $stdout, 'stderr' => $stderr] = runPhpSubprocess($prelude.$code, $env);

    $decoded = json_decode($stdout, true);

    if (! is_array($decoded)) {
        test()->fail("subprocess did not print JSON. stdout: {$stdout} stderr: {$stderr}");
    }

    return $decoded;
}

$autoName = PollDriver::isSupported() ? 'poll' : 'select';
$autoClass = PollDriver::isSupported() ? PollDriver::class : StreamSelectDriver::class;

dataset('names', [
    'poll' => ['poll', 'poll', PollDriver::class],
    'POLL padded' => ["  POLL\n", 'poll', PollDriver::class],
    'select' => ['select', 'select', StreamSelectDriver::class],
    'Select tab' => ["\tSelect ", 'select', StreamSelectDriver::class],
    'auto' => ['auto', $autoName, $autoClass],
    'AUTO padded' => [' AUTO ', $autoName, $autoClass],
    'empty means auto' => ['  ', $autoName, $autoClass],
]);

it('resolves and creates every accepted value', function (string $name, string $resolved, string $class) {
    expect(DriverFactory::resolve($name))->toBe($resolved);

    $driver = (new DriverFactory())->create($name);

    expect($driver::class)->toBe($class)
        ->and(Loop::getDriver())->not->toBe($driver);
})->with('names');

it('creates exactly a StreamSelectDriver for the select rollback', function () {
    expect((new DriverFactory())->create('select')::class)->toBe(StreamSelectDriver::class);
});

it('rejects an unknown name, including the former revolt, and lists the accepted values', function (string $name) {
    (new DriverFactory())->create($name);
})->with(['epoll', 'revolt', 'kqueue'])->throws(InvalidArgumentException::class, 'expected one of: auto, poll, select.');

it('rejects an unknown name from the environment', function () {
    putenv(DriverFactory::ENV.'=libuv');

    DriverFactory::resolve();
})->throws(InvalidArgumentException::class, 'libuv');

it('resolves auto to poll where kqueue or epoll exists, and resolve and create agree', function () {
    expect(PollDriver::isSupported())->toBe(Io\Poll\Backend::Kqueue->isAvailable() || Io\Poll\Backend::Epoll->isAvailable())
        ->and(PollDriver::isSupported())->toBeTrue()
        ->and(DriverFactory::resolve('auto'))->toBe('poll')
        ->and((new DriverFactory())->create('auto'))->toBeInstanceOf(PollDriver::class);
});

it('never makes poll(2) the loop backend through auto', function () {
    $handle = (new DriverFactory())->create('auto')->getHandle();

    expect($handle)->toBeInstanceOf(Io\Poll\Context::class)
        ->and($handle->getBackend())->toBeIn([Io\Poll\Backend::Kqueue, Io\Poll\Backend::Epoll]);
});

it('defaults to auto when the environment variable is unset', function () {
    expect(DriverFactory::resolve())->toBe(DriverFactory::resolve('auto'));
});

it('reads the environment variable when no name is given', function () {
    putenv(DriverFactory::ENV.'= Select ');

    expect(DriverFactory::resolve())->toBe('select')
        ->and((new DriverFactory())->create()::class)->toBe(StreamSelectDriver::class);
});

it('prefers an explicit name over the environment variable', function () {
    putenv(DriverFactory::ENV.'=poll');

    expect(DriverFactory::resolve('select'))->toBe('select');
});

it('honours the environment variable for the lazily created default driver', function (string $value, string $class) {
    $result = loopInFreshProcess(<<<'PHP'
        echo json_encode(['class' => Fledge\Loop::getDriver()::class]);
        PHP, env: [DriverFactory::ENV => $value]);

    expect($result)->toBe(['class' => $class]);
})->with([
    'select' => ['select', StreamSelectDriver::class],
    'poll' => ['poll', PollDriver::class],
    'auto' => ['auto', $autoClass],
]);

it('fails loudly at first use of the loop when the environment variable is invalid', function () {
    $result = loopInFreshProcess(<<<'PHP'
        try {
            Fledge\Loop::defer(static fn () => null);
            $out = ['error' => null];
        } catch (Throwable $exception) {
            $out = ['error' => $exception::class, 'message' => $exception->getMessage()];
        }
        echo json_encode($out);
        PHP, env: [DriverFactory::ENV => 'revolt']);

    expect($result['error'])->toBe(UnknownDriverException::class)
        ->and($result['message'])->toContain('"revolt"')
        ->and($result['message'])->toContain('expected one of: auto, poll, select');
});

it('installs the driver named by the environment variable when no name is given', function () {
    $result = loopInFreshProcess(<<<'PHP'
        putenv(Fledge\Loop\DriverFactory::ENV.'=poll');
        $driver = Fledge\Loop::install();
        echo json_encode([
            'class' => $driver::class,
            'installed' => Fledge\Loop::getDriver() === $driver,
        ]);
        PHP);

    expect($result)->toBe(['class' => PollDriver::class, 'installed' => true]);
});

it('does not create the default driver when install() finds none', function () {
    // An invalid FLEDGE_EVENT_LOOP would make the lazily created default throw; an explicit name must not need it.
    $result = loopInFreshProcess(<<<'PHP'
        $driver = Fledge\Loop::install('select');
        echo json_encode(['class' => $driver::class, 'installed' => Fledge\Loop::getDriver() === $driver]);
        PHP, env: [DriverFactory::ENV => 'bogus']);

    expect($result)->toBe(['class' => StreamSelectDriver::class, 'installed' => true]);
});

it('installs on an idle empty loop and runs a deferred callback and a timer', function (string $name, string $class) {
    $result = loopInFreshProcess(<<<'PHP'
        $driver = Fledge\Loop::install($name);
        $installed = Fledge\Loop::getDriver() === $driver;

        $order = [];
        Fledge\Loop::delay(0.01, function () use (&$order) {
            $order[] = 'timer';
        });
        Fledge\Loop::defer(function () use (&$order) {
            $order[] = 'defer';
        });

        Fledge\Loop::run();

        echo json_encode(['class' => $driver::class, 'installed' => $installed, 'order' => $order]);
        PHP, ['name' => $name]);

    expect($result)->toBe(['class' => $class, 'installed' => true, 'order' => ['defer', 'timer']]);
})->with([
    'poll' => ['poll', PollDriver::class],
    'select' => ['select', StreamSelectDriver::class],
    'auto' => ['auto', $autoClass],
]);

it('always installs a fresh driver, even of the same kind', function () {
    $result = loopInFreshProcess(<<<'PHP'
        $first = Fledge\Loop::install('poll');
        $second = Fledge\Loop::install('poll');
        echo json_encode([
            'class' => $second::class,
            'fresh' => $second !== $first,
            'installed' => Fledge\Loop::getDriver() === $second,
        ]);
        PHP);

    expect($result)->toBe(['class' => PollDriver::class, 'fresh' => true, 'installed' => true]);
});

it('refuses to install while the loop is running', function () {
    $result = loopInFreshProcess(<<<'PHP'
        $before = Fledge\Loop::getDriver();
        $caught = null;

        Fledge\Loop::defer(function () use (&$caught) {
            try {
                Fledge\Loop::install('select');
            } catch (Throwable $exception) {
                $caught = $exception;
            }
        });

        Fledge\Loop::run();

        echo json_encode([
            'class' => $caught === null ? null : $caught::class,
            'message' => $caught?->getMessage(),
            'unchanged' => Fledge\Loop::getDriver() === $before,
        ]);
        PHP);

    expect($result['class'])->toBe(LoopStateException::class)
        ->and($result['message'])->toContain('while the event loop is running')
        ->and($result['unchanged'])->toBeTrue();
});

it('refuses to install after {main} awaited, until the loop has run to completion', function () {
    $result = loopInFreshProcess(<<<'PHP'
        $before = Fledge\Loop::getDriver();
        $suspension = Fledge\Loop::getSuspension();
        Fledge\Loop::defer(fn () => $suspension->resume());
        $suspension->suspend();

        $message = null;
        try {
            Fledge\Loop::install('select');
        } catch (LogicException $exception) {
            $message = $exception->getMessage();
        }
        $unchanged = Fledge\Loop::getDriver() === $before;

        Fledge\Loop::run();
        $after = Fledge\Loop::install('select');

        echo json_encode(['message' => $message, 'unchanged' => $unchanged, 'after' => $after::class]);
        PHP);

    expect($result['message'])->toContain('while the event loop is running')
        ->and($result['unchanged'])->toBeTrue()
        ->and($result['after'])->toBe(StreamSelectDriver::class);
});

it('refuses to install while callbacks are registered', function (string $case) {
    $result = loopInFreshProcess(<<<'PHP'
        $before = Fledge\Loop::getDriver();
        $id = match ($case) {
            'enabled defer' => Fledge\Loop::defer(static fn () => null),
            'disabled timer' => Fledge\Loop::disable(Fledge\Loop::delay(10, static fn () => null)),
            'unreferenced repeat' => Fledge\Loop::unreference(Fledge\Loop::repeat(10, static fn () => null)),
            'unreferenced readable' => Fledge\Loop::unreference(
                Fledge\Loop::onReadable(fopen('php://memory', 'r'), static fn () => null),
            ),
        };

        $caught = null;
        try {
            Fledge\Loop::install('select');
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        $unchanged = Fledge\Loop::getDriver() === $before;

        Fledge\Loop::cancel($id);
        $after = Fledge\Loop::install('select');

        echo json_encode([
            'class' => $caught === null ? null : $caught::class,
            'message' => $caught?->getMessage(),
            'unchanged' => $unchanged,
            'replaced' => $after !== $before && Fledge\Loop::getDriver() === $after,
        ]);
        PHP, ['case' => $case]);

    expect($result['class'])->toBe(LoopStateException::class)
        ->and($result['message'])->toContain('1 callback(s) registered')
        ->and($result['unchanged'])->toBeTrue()
        ->and($result['replaced'])->toBeTrue();
})->with(['enabled defer', 'disabled timer', 'unreferenced repeat', 'unreferenced readable']);

it('leaves the active driver untouched when the name is invalid', function () {
    $before = Loop::getDriver();

    expect(fn () => Loop::install('kqueue'))->toThrow(InvalidArgumentException::class)
        ->and(Loop::getDriver())->toBe($before)
        ->and(fn () => Loop::afterFork('kqueue'))->toThrow(InvalidArgumentException::class)
        ->and(Loop::getDriver())->toBe($before);
});

it('continues a callback id sequence forward only', function () {
    $driver = new StreamSelectDriver();
    $driver->continueCallbackIds('zz');
    $first = $driver->defer(static fn () => null);
    $driver->continueCallbackIds('b'); // behind the current id: ignored
    $second = $driver->defer(static fn () => null);

    expect($first)->toBe('zz')
        ->and($second)->toBe('aaa')
        ->and(fn () => $driver->continueCallbackIds('A1'))->toThrow(InvalidArgumentException::class);
});

it('opens no descriptor for timers, deferred callbacks, signals and suspensions, and one at the first stream', function () {
    $result = loopInFreshProcess(<<<'PHP'
        function openDescriptors(): array
        {
            $open = [];
            for ($fd = 0; $fd < 256; $fd++) {
                $probe = @fopen("php://fd/{$fd}", 'r');
                if ($probe !== false) {
                    $open[] = $fd;
                    fclose($probe);
                }
            }

            return $open;
        }

        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $base = openDescriptors();
        $driver = Fledge\Loop::install('poll');
        Fledge\Loop::delay(0.01, static fn () => null);
        Fledge\Loop::defer(static fn () => null);
        if (extension_loaded('pcntl')) {
            Fledge\Loop::unreference(Fledge\Loop::onSignal(SIGUSR1, static fn () => null));
        }
        $suspension = Fledge\Loop::getSuspension();
        Fledge\Loop::delay(0.02, static fn () => $suspension->resume());
        $suspension->suspend();
        Fledge\Loop::run();
        $withoutStreams = array_values(array_diff(openDescriptors(), $base));

        $id = Fledge\Loop::onReadable($a, static fn () => null);
        fwrite($b, 'x');
        Fledge\Loop::defer(static fn () => Fledge\Loop::cancel($id));
        Fledge\Loop::run();
        $withStream = count(array_diff(openDescriptors(), $base));

        echo json_encode(['withoutStreams' => $withoutStreams, 'withStream' => $withStream]);
        PHP);

    expect($result)->toBe(['withoutStreams' => [], 'withStream' => 1]);
});

it('opens the descriptor at the first getHandle() call and keeps returning it', function () {
    $driver = new PollDriver();
    $handle = $driver->getHandle();

    expect($handle)->toBeInstanceOf(Io\Poll\Context::class)
        ->and($driver->getHandle())->toBe($handle);
});

it('registers the exit hook once per process, not once per driver', function () {
    $result = loopInFreshProcess(<<<'PHP'
        $run = static function (int $count): void {
            for ($i = 0; $i < $count; $i++) {
                $driver = new Fledge\Loop\Driver\PollDriver();
                $driver->getHandle();
                unset($driver);
                gc_collect_cycles();
            }
        };
        $grown = static function (int $count) use ($run): int {
            gc_collect_cycles();
            $before = memory_get_usage();
            $run($count);
            gc_collect_cycles();

            return memory_get_usage() - $before;
        };
        $run(500); // warm up allocator and caches
        $small = $grown(1000);
        $large = $grown(4000);
        echo json_encode(['perDriver' => ($large - $small) / 3000]);
        PHP);

    // A registered shutdown closure per driver costs a few hundred bytes each. The slope between two batch sizes, not an
    // absolute bound, so a one-off allocator step (64 KiB is about 22 bytes per driver here) cannot fail the test.
    expect($result['perDriver'])->toBeLessThan(40);
});

it('reports the loop fiber only inside the loop fiber', function () {
    $result = loopInFreshProcess(<<<'PHP'
        $seen = ['main' => Fledge\Loop::inLoopFiber()];
        (new Fiber(static function () use (&$seen) {
            $seen['fiber'] = Fledge\Loop::inLoopFiber();
        }))->start();
        Fledge\Loop::defer(static function () use (&$seen) {
            $seen['callback'] = Fledge\Loop::inLoopFiber();
        });

        // A plain pcntl handler (not a loop callback) runs from the driver's pcntl_signal_dispatch() in its dispatch,
        // which executes in the loop fiber.
        if (function_exists('pcntl_signal') && function_exists('posix_kill')) {
            pcntl_signal(SIGUSR2, static function () use (&$seen) {
                $seen['dispatch'] = Fledge\Loop::inLoopFiber();
            });
            Fledge\Loop::delay(0.01, static function (): void {
                posix_kill(getmypid(), SIGUSR2);
            });
            Fledge\Loop::delay(0.05, static fn () => null);
        }

        Fledge\Loop::run();
        echo json_encode($seen);
        PHP);

    expect($result['main'])->toBeFalse()
        ->and($result['fiber'])->toBeFalse()
        ->and($result['callback'])->toBeFalse();

    if (array_key_exists('dispatch', $result)) {
        expect($result['dispatch'])->toBeTrue();
    }
});

it('replaces an idle third-party driver through install() and refuses it in afterFork() and fork()', function () {
    $result = loopInFreshProcess(<<<'PHP'
        use Fledge\Loop;
        use Fledge\Loop\Tests\Fixtures\DecoratingDriver;

        $custom = new DecoratingDriver(new Fledge\Loop\Driver\StreamSelectDriver());
        Loop::setDriver($custom);
        $id = Loop::defer(static fn () => null);
        $messages = [];
        foreach (['afterFork' => static fn () => Loop::afterFork('select'), 'fork' => static fn () => Loop::fork()] as $method => $call) {
            try {
                $call();
                $messages[$method] = null;
            } catch (LogicException $exception) {
                $messages[$method] = $exception->getMessage();
            }
        }
        $unchanged = Loop::getDriver() === $custom && Loop::getIdentifiers() === [$id];

        Loop::cancel($id);
        $installed = Loop::install('select');

        echo json_encode([
            'messages' => $messages,
            'unchanged' => $unchanged,
            'installed' => $installed::class,
            'active' => Loop::getDriver() === $installed,
        ]);
        PHP);

    expect($result['unchanged'])->toBeTrue()
        ->and($result['installed'])->toBe(StreamSelectDriver::class)
        ->and($result['active'])->toBeTrue()
        ->and($result['messages']['afterFork'])->toContain('Fledge\\Loop::afterFork() cannot abandon the active Fledge\\Loop\\Tests\\Fixtures\\DecoratingDriver')
        ->and($result['messages']['fork'])->toContain('Fledge\\Loop::fork() cannot abandon')
        ->and($result['messages']['fork'])->toContain('Loop::setDriver() in the child');
});
