<?php

declare(strict_types=1);

use Fledge\Loop\Driver\PollDriver;
use Fledge\Loop\Tests\Fixtures\DecoratingDriver;

/*
 * Loop::hasDriver() and Loop::driverName() report the process-wide driver without creating one, so every case runs in
 * a fresh PHP process, where no driver exists until the code makes one.
 */

/**
 * @return array<string, mixed>
 */
function driverInfoInFreshProcess(string $code): array
{
    $result = runPhpSubprocess($code, timeout: 30.0);
    $decoded = json_decode($result['stdout'], true);

    if (! is_array($decoded)) {
        test()->fail("subprocess did not print JSON. stdout: {$result['stdout']} stderr: {$result['stderr']}");
    }

    return $decoded;
}

it('reports no driver without creating one', function () {
    $result = driverInfoInFreshProcess(<<<'PHP'
        use Fledge\Loop;

        $before = [Loop::hasDriver(), Loop::driverName(), Loop::hasDriver()];
        // install() still finds no driver: the queries created none.
        $driver = Loop::install('select');
        echo json_encode(['before' => $before, 'installed' => $driver === Loop::getDriver()]);
        PHP);

    expect($result)->toBe(['before' => [false, null, false], 'installed' => true]);
});

it('reports the lazily created default driver', function () {
    $result = driverInfoInFreshProcess(<<<'PHP'
        use Fledge\Loop;

        Loop::getDriver();
        echo json_encode([Loop::hasDriver(), Loop::driverName()]);
        PHP);

    expect($result)->toBe([true, PollDriver::isSupported() ? 'poll' : 'select']);
});

it('reports the installed driver', function (string $name) {
    $result = driverInfoInFreshProcess(
        '$name = '.var_export($name, true).";\n".<<<'PHP'
        use Fledge\Loop;

        Loop::install($name);
        $first = [Loop::hasDriver(), Loop::driverName()];
        // A second install() replaces an idle driver; the name follows.
        Loop::install($name === 'poll' ? 'select' : 'poll');
        echo json_encode(['first' => $first, 'second' => Loop::driverName()]);
        PHP,
    );

    expect($result)->toBe(['first' => [true, $name], 'second' => $name === 'poll' ? 'select' : 'poll']);
})->with(['poll', 'select'])->skip(! PollDriver::isSupported(), 'needs kqueue or epoll');

it('reports poll for a PollDriver on any backend, set directly', function () {
    $result = driverInfoInFreshProcess(<<<'PHP'
        use Fledge\Loop;

        Loop::setDriver(new Fledge\Loop\Driver\PollDriver(Io\Poll\Backend::Poll));
        echo json_encode([Loop::hasDriver(), Loop::driverName()]);
        PHP);

    expect($result)->toBe([true, 'poll']);
});

it('reports the class name of a third-party driver', function () {
    $result = driverInfoInFreshProcess(<<<'PHP'
        use Fledge\Loop;

        Loop::setDriver(new Fledge\Loop\Tests\Fixtures\DecoratingDriver(new Fledge\Loop\Driver\StreamSelectDriver()));
        echo json_encode([Loop::hasDriver(), Loop::driverName()]);
        PHP);

    expect($result)->toBe([true, DecoratingDriver::class]);
});

it('reports the inherited driver in a forked child, then the one afterFork() installed', function () {
    $result = driverInfoInFreshProcess(<<<'PHP'
        use Fledge\Loop;

        Loop::install('poll');
        Loop::unreference(Loop::repeat(10, static fn () => null));
        [$report, $reportPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $pid = pcntl_fork();

        if ($pid === 0) {
            $inherited = [Loop::hasDriver(), Loop::driverName()];
            Loop::afterFork('select');
            fwrite($reportPeer, json_encode(['inherited' => $inherited, 'after' => [Loop::hasDriver(), Loop::driverName()]]));
            exit(0);
        }

        pcntl_waitpid($pid, $status);
        fclose($reportPeer);
        $child = json_decode((string) stream_get_contents($report), true);
        echo json_encode(['child' => $child, 'parent' => Loop::driverName(), 'exit' => pcntl_wexitstatus($status)]);
        PHP);

    expect($result)->toBe([
        'child' => ['inherited' => [true, 'poll'], 'after' => [true, 'select']],
        'parent' => 'poll',
        'exit' => 0,
    ]);
})->skip(! function_exists('pcntl_fork') || ! PollDriver::isSupported(), 'needs pcntl and kqueue or epoll');

it('reports no driver to a destructor that runs while the loop swaps drivers', function (string $swap) {
    $result = driverInfoInFreshProcess(
        '$swap = '.var_export($swap, true).";\n".<<<'PHP'
        use Fledge\Loop;

        final class SwapObserver
        {
            /** @var list<array{bool, string|null}> */
            public static array $seen = [];

            public function __destruct()
            {
                self::$seen[] = [Loop::hasDriver(), Loop::driverName()];
            }
        }

        // Only the old driver's error handler holds the observer, so it is destroyed while that driver is collected.
        Loop::install('poll');
        $observer = new SwapObserver();
        Loop::setErrorHandler(static function (Throwable $error) use ($observer): void {
        });
        unset($observer);

        if ($swap === 'setDriver') {
            Loop::setDriver(new Fledge\Loop\Driver\StreamSelectDriver());
        } else {
            Loop::install('select');
        }

        echo json_encode(['seen' => SwapObserver::$seen, 'after' => Loop::driverName()]);
        PHP,
    );

    expect($result)->toBe(['seen' => [[true, null]], 'after' => 'select']);
})->with(['setDriver', 'install'])->skip(! PollDriver::isSupported(), 'needs kqueue or epoll');

it('reports no driver to a destructor that runs during afterFork()', function () {
    $result = driverInfoInFreshProcess(<<<'PHP'
        use Fledge\Loop;

        final class ForkSwapObserver
        {
            /** @var resource */
            public static $report;

            public function __destruct()
            {
                fwrite(self::$report, json_encode([Loop::hasDriver(), Loop::driverName()]));
            }
        }

        Loop::install('select');
        $observer = new ForkSwapObserver();
        Loop::setErrorHandler(static function (Throwable $error) use ($observer): void {
        });
        unset($observer);
        [$report, $reportPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $pid = pcntl_fork();

        if ($pid === 0) {
            ForkSwapObserver::$report = $reportPeer;
            Loop::afterFork('poll');
            fwrite($reportPeer, '|'.Loop::driverName());
            exit(0);
        }

        // The parent's own observer must not report into the pipe at its exit.
        ForkSwapObserver::$report = fopen('php://memory', 'w');
        pcntl_waitpid($pid, $status);
        fclose($reportPeer);
        echo json_encode(['child' => stream_get_contents($report), 'exit' => pcntl_wexitstatus($status)]);
        PHP);

    expect($result)->toBe(['child' => '[true,null]|poll', 'exit' => 0]);
})->skip(! function_exists('pcntl_fork') || ! PollDriver::isSupported(), 'needs pcntl and kqueue or epoll');
