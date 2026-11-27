<?php

declare(strict_types=1);

namespace Fledge\Loop\Tests\Fixtures;

use Fledge\Loop\CallbackType;
use Fledge\Loop\Driver;
use Fledge\Loop\Suspension;
use Time\Duration;

/**
 * A third-party Driver implementation (it does not extend Fledge's driver base): forwards every call to an inner
 * driver, the shape of a tracing or metrics decorator. Used to pin down what Loop::afterFork(), Loop::fork() and
 * Loop::install() do with a driver they cannot abandon from the inside.
 */
final class DecoratingDriver implements Driver
{
    public function __construct(private readonly Driver $inner)
    {
    }

    public function run(): void
    {
        $this->inner->run();
    }

    public function stop(): void
    {
        $this->inner->stop();
    }

    public function getSuspension(): Suspension
    {
        return $this->inner->getSuspension();
    }

    public function isRunning(): bool
    {
        return $this->inner->isRunning();
    }

    public function queue(\Closure $closure, mixed ...$args): void
    {
        $this->inner->queue($closure, ...$args);
    }

    public function defer(\Closure $closure): string
    {
        return $this->inner->defer($closure);
    }

    public function delay(Duration|float $delay, \Closure $closure): string
    {
        return $this->inner->delay($delay, $closure);
    }

    public function repeat(Duration|float $interval, \Closure $closure): string
    {
        return $this->inner->repeat($interval, $closure);
    }

    public function onReadable(mixed $stream, \Closure $closure): string
    {
        return $this->inner->onReadable($stream, $closure);
    }

    public function onWritable(mixed $stream, \Closure $closure): string
    {
        return $this->inner->onWritable($stream, $closure);
    }

    public function onSignal(int $signal, \Closure $closure): string
    {
        return $this->inner->onSignal($signal, $closure);
    }

    public function enable(string $callbackId): string
    {
        return $this->inner->enable($callbackId);
    }

    public function cancel(string $callbackId): void
    {
        $this->inner->cancel($callbackId);
    }

    public function disable(string $callbackId): string
    {
        return $this->inner->disable($callbackId);
    }

    public function reference(string $callbackId): string
    {
        return $this->inner->reference($callbackId);
    }

    public function unreference(string $callbackId): string
    {
        return $this->inner->unreference($callbackId);
    }

    public function setErrorHandler(?\Closure $errorHandler): void
    {
        $this->inner->setErrorHandler($errorHandler);
    }

    public function getErrorHandler(): ?\Closure
    {
        return $this->inner->getErrorHandler();
    }

    public function getHandle(): mixed
    {
        return $this->inner->getHandle();
    }

    public function getIdentifiers(): array
    {
        return $this->inner->getIdentifiers();
    }

    public function getType(string $callbackId): CallbackType
    {
        return $this->inner->getType($callbackId);
    }

    public function isEnabled(string $callbackId): bool
    {
        return $this->inner->isEnabled($callbackId);
    }

    public function isReferenced(string $callbackId): bool
    {
        return $this->inner->isReferenced($callbackId);
    }

    public function __debugInfo(): array
    {
        return $this->inner->__debugInfo();
    }
}
