<?php

declare(strict_types=1);

// Derived from revolt/event-loop v1.1.0, src/EventLoop/Internal/DeferCallback.php.
// MIT License; copyright notices in LICENSE.

namespace Fledge\Loop\Internal;

use Fledge\Loop\CallbackType;

/** @internal */
final class DeferCallback extends DriverCallback
{
    #[\Override]
    public function getType(): CallbackType
    {
        return CallbackType::Defer;
    }
}
