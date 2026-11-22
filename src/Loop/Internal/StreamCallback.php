<?php

declare(strict_types=1);

// Derived from revolt/event-loop v1.1.0, src/EventLoop/Internal/StreamCallback.php.
// MIT License; copyright notices in LICENSE.

namespace Fledge\Loop\Internal;

/** @internal */
abstract class StreamCallback extends DriverCallback
{
    /**
     * @param resource $stream
     */
    public function __construct(
        string $id,
        \Closure $closure,
        public readonly mixed $stream,
    ) {
        parent::__construct($id, $closure);
    }
}
