<?php

declare(strict_types=1);

use Fledge\Loop\DriverFactory;
use Fledge\Loop\ForkFailedException;
use Fledge\Loop\ForkUnwindException;
use Fledge\Loop\LoopStateException;
use Fledge\Loop\UnknownDriverException;

/*
 * The dedicated exception classes extend the SPL types, so a catch of the parent type also catches them, while a catch
 * of the class itself can tell a refused state from an unknown driver name (an \InvalidArgumentException is a
 * \LogicException too).
 */

it('extends the SPL types', function (string $class, string $parent) {
    expect(is_subclass_of($class, $parent))->toBeTrue()
        ->and((new ReflectionClass($class))->isFinal())->toBeTrue();
})->with([
    'LoopStateException' => [LoopStateException::class, LogicException::class],
    'UnknownDriverException' => [UnknownDriverException::class, InvalidArgumentException::class],
    'ForkUnwindException' => [ForkUnwindException::class, RuntimeException::class],
    'ForkFailedException' => [ForkFailedException::class, RuntimeException::class],
]);

it('keeps an unknown driver name apart from a refused state', function () {
    expect(fn () => DriverFactory::resolve('libuv'))->toThrow(UnknownDriverException::class, 'expected one of: auto, poll, select.')
        ->and(is_subclass_of(UnknownDriverException::class, LoopStateException::class))->toBeFalse()
        ->and(is_subclass_of(LoopStateException::class, InvalidArgumentException::class))->toBeFalse();
});

it('carries every unwinding error, the first one as previous', function () {
    $errors = [new RuntimeException('first'), new LogicException('second')];
    $exception = new ForkUnwindException('unwound', $errors);

    expect($exception->getPrevious())->toBe($errors[0])
        ->and($exception->getUnwindErrors())->toBe($errors)
        ->and($exception->getMessage())->toBe('unwound');
});

it('refuses install() with a LoopStateException that a LogicException catch still catches', function () {
    $result = runPhpSubprocess(<<<'PHP'
        use Fledge\Loop;

        Loop::install('select');
        Loop::defer(static fn () => null);
        $caught = [];

        try {
            Loop::install('select');
        } catch (LogicException $exception) {
            $caught[] = $exception::class;
        }

        try {
            Loop::install('libuv');
        } catch (InvalidArgumentException $exception) {
            $caught[] = $exception::class;
        }

        try {
            Loop::afterFork('select');
        } catch (LogicException $exception) {
            $caught[] = $exception::class;
        }

        echo json_encode($caught);
        PHP, timeout: 30.0);

    expect(json_decode($result['stdout'], true))->toBe([
        LoopStateException::class,
        UnknownDriverException::class,
        LoopStateException::class,
    ], $result['stderr']);
});

it('throws a ForkFailedException when pcntl_fork() fails', function () {
    $result = runPhpSubprocess(<<<'PHP'
        // At a process limit of 1 the fork fails with EAGAIN, unless the process may exceed it (root in a container).
        posix_setrlimit(POSIX_RLIMIT_NPROC, 1, 1);

        try {
            $pid = Fledge\Loop::fork();
        } catch (Throwable $exception) {
            echo json_encode([$exception::class, $exception instanceof RuntimeException, Fledge\Loop::driverName()]);
            exit(0);
        }

        if ($pid === 0) {
            exit(0);
        }

        pcntl_waitpid($pid, $status);
        echo json_encode('forked');
        PHP, timeout: 30.0);

    $outcome = json_decode($result['stdout'], true);

    if ($outcome === 'forked') {
        $this->markTestSkipped('this process may exceed RLIMIT_NPROC, so pcntl_fork() cannot be made to fail');
    }

    expect($outcome)->toBe([ForkFailedException::class, true, null], "stderr: {$result['stderr']}");
})->skip(! function_exists('pcntl_fork') || ! function_exists('posix_setrlimit'), 'needs pcntl and posix');
