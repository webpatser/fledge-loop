<?php

declare(strict_types=1);

// Derived from revolt/event-loop v1.1.0, src/EventLoop/Internal/SignalCallback.php.
// MIT License; copyright notices in LICENSE.

namespace Fledge\Loop\Internal;

use Fledge\Loop\CallbackType;

/** @internal */
final class SignalCallback extends DriverCallback
{
    public function __construct(
        string $id,
        \Closure $closure,
        public readonly int $signal
    ) {
        parent::__construct($id, $closure);
    }

    #[\Override]
    public function getType(): CallbackType
    {
        return CallbackType::Signal;
    }
}
