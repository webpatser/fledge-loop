<?php

declare(strict_types=1);

// Derived from revolt/event-loop v1.1.0, src/EventLoop/Internal/TimerCallback.php.
// MIT License; copyright notices in LICENSE.

namespace Fledge\Loop\Internal;

use Fledge\Loop\CallbackType;

/** @internal */
final class TimerCallback extends DriverCallback
{
    public function __construct(
        string $id,
        public readonly float $interval,
        \Closure $callback,
        public float $expiration,
        public readonly bool $repeat = false,
    ) {
        parent::__construct($id, $callback);
    }

    #[\Override]
    public function getType(): CallbackType
    {
        return $this->repeat ? CallbackType::Repeat : CallbackType::Delay;
    }
}
