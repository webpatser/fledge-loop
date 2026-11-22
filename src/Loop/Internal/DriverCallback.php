<?php

declare(strict_types=1);

// Derived from revolt/event-loop v1.1.0, src/EventLoop/Internal/DriverCallback.php.
// MIT License; copyright notices in LICENSE.

namespace Fledge\Loop\Internal;

use Fledge\Loop\CallbackType;

/**
 * @internal
 */
abstract class DriverCallback
{
    public bool $invokable = false;

    public bool $enabled = true;

    public bool $referenced = true;

    public function __construct(
        public readonly string $id,
        public readonly \Closure $closure
    ) {
    }

    abstract public function getType(): CallbackType;

    /**
     * @param string $property
     */
    public function __get(string $property): never
    {
        throw new \Error("Unknown property '{$property}'");
    }

    /**
     * @param string $property
     * @param mixed  $value
     */
    public function __set(string $property, mixed $value): never
    {
        throw new \Error("Unknown property '{$property}'");
    }
}
