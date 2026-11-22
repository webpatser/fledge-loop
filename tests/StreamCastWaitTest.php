<?php

declare(strict_types=1);

/*
 * stream_select() calls a user-space wrapper's stream_cast() inside the wait call, after the loop's last mayBlock()
 * check and right before the kernel wait. Code in stream_cast() that stops the loop or adds a timer there cannot end
 * a wait that has already been decided, so both drivers cap every blocking stream_select() at
 * IdleSleep::SIGNAL_WAIT_CAP. PollDriver selects user-space wrapper streams through its fallback set.
 *
 * The wrapper acts only when the stream_select() that casts it blocks (its timeout argument is not zero), the worst
 * case: a zero-timeout select first would not catch it. An unreferenced 5 s timer ends a run that would otherwise hang.
 */

const STREAM_CAST_WAIT = <<<'PHP'
    use Fledge\Loop;
    use Fledge\Loop\Internal\IdleSleep;

    final class BlockingCastWrapper
    {
        /** @var resource|null */
        public $context;

        /** @var resource */
        public static $socket;

        public static ?\Closure $onBlockingCast = null;

        public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
        {
            return true;
        }

        /** @return resource */
        public function stream_cast(int $castAs)
        {
            $frame = debug_backtrace(0, 2)[1] ?? [];
            $args = $frame['args'] ?? [];
            $blocking = ($frame['function'] ?? '') === 'stream_select'
                && (($args[3] ?? null) === null || $args[3] > 0 || ($args[4] ?? 0) > 0);

            if ($blocking && self::$onBlockingCast !== null) {
                $action = self::$onBlockingCast;
                self::$onBlockingCast = null;
                $action();
            }

            return self::$socket;
        }

        public function stream_read(int $count): string
        {
            return '';
        }

        public function stream_eof(): bool
        {
            return false;
        }

        public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
        {
            return false;
        }
    }

    Loop::setDriver($name === 'select' ? new Fledge\Loop\Driver\StreamSelectDriver() : new Fledge\Loop\Driver\PollDriver());

    BlockingCastWrapper::$socket = stream_socket_server('tcp://127.0.0.1:0');
    stream_wrapper_register('blockingcast', BlockingCastWrapper::class);
    $stream = fopen('blockingcast://stream', 'r');
    $watcher = Loop::onReadable($stream, static fn () => null);

    $actedAt = null;
    $guardFired = false;
    $act = match ($action) {
        'stop' => static fn () => Loop::getDriver()->stop(),
        'delay' => static fn () => Loop::delay(0, static fn () => Loop::getDriver()->stop()),
    };

    Loop::delay(0.02, static function () use ($act, &$actedAt): void {
        BlockingCastWrapper::$onBlockingCast = static function () use ($act, &$actedAt): void {
            $actedAt = hrtime(true);
            $act();
        };
    });
    $guard = Loop::unreference(Loop::delay(5, static function () use (&$guardFired): void {
        $guardFired = true;
        Loop::getDriver()->stop();
    }));

    Loop::run();
    $returnedAt = hrtime(true);
    Loop::cancel($guard);
    Loop::cancel($watcher);

    echo json_encode([
        'cap' => IdleSleep::SIGNAL_WAIT_CAP,
        'acted' => $actedAt !== null,
        'guardFired' => $guardFired,
        'latency' => $actedAt === null ? null : ($returnedAt - $actedAt) / 1e9,
    ]);
    PHP;

it('ends a wait within the cap when stream_cast() stops the loop or adds a timer', function (string $name, string $action) {
    $result = runPhpSubprocess(
        '$name = '.var_export($name, true).'; $action = '.var_export($action, true).";\n".STREAM_CAST_WAIT,
        timeout: 30.0,
    );

    expect($result['timedOut'])->toBeFalse("stderr: {$result['stderr']}")
        ->and($result['exit'])->toBe(0, "stdout: {$result['stdout']} stderr: {$result['stderr']}");

    $report = json_decode($result['stdout'], true);

    expect($report)->toBeArray("stdout: {$result['stdout']} stderr: {$result['stderr']}")
        ->and($report['acted'])->toBeTrue()
        ->and($report['guardFired'])->toBeFalse(json_encode($report))
        // Healthy: at most the cap plus scheduling delay; uncapped: the 5 s guard. 1 s of margin for shared CI runners.
        ->and($report['latency'])->toBeLessThan($report['cap'] + 1.0, json_encode($report));
})->with(['select', 'poll'])->with(['stop', 'delay']);
