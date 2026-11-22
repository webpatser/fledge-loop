<?php

declare(strict_types=1);

// Derived from revolt/event-loop v1.1.0, src/EventLoop/CallbackType.php.
// MIT License; copyright notices in LICENSE.

namespace Fledge\Loop;

enum CallbackType
{
    case Defer;
    case Delay;
    case Repeat;
    case Readable;
    case Writable;
    case Signal;
}
