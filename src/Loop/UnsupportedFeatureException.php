<?php

declare(strict_types=1);

// Derived from revolt/event-loop v1.1.0, src/EventLoop/UnsupportedFeatureException.php.
// MIT License; copyright notices in LICENSE.

namespace Fledge\Loop;

/**
 * MUST be thrown if a feature is not supported by the system.
 *
 * This might happen if ext-pcntl is missing and the loop driver doesn't support another way to dispatch signals.
 */
final class UnsupportedFeatureException extends \Exception
{
}
