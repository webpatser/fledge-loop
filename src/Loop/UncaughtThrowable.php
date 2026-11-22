<?php

declare(strict_types=1);

// Derived from revolt/event-loop v1.1.0, src/EventLoop/UncaughtThrowable.php.
// MIT License; copyright notices in LICENSE.

namespace Fledge\Loop;

use Fledge\Loop\Internal\ClosureHelper;

final class UncaughtThrowable extends \Error
{
    public static function throwingCallback(\Closure $closure, \Throwable $previous): self
    {
        return new self(
            "Uncaught %s thrown in event loop callback %s; use Fledge\Loop::setErrorHandler() to gracefully handle such exceptions%s",
            $closure,
            $previous
        );
    }

    public static function throwingErrorHandler(\Closure $closure, \Throwable $previous): self
    {
        return new self("Uncaught %s thrown in event loop error handler %s%s", $closure, $previous);
    }

    private function __construct(string $message, \Closure $closure, \Throwable $previous)
    {
        parent::__construct(\sprintf(
            $message,
            \str_replace("\0", '@', \get_class($previous)), // replace NUL-byte in anonymous class name
            ClosureHelper::getDescription($closure),
            $previous->getMessage() !== '' ? ': ' . $previous->getMessage() : ''
        ), 0, $previous);
    }
}
