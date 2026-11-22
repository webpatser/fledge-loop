<?php

declare(strict_types=1);

// Derived from revolt/event-loop v1.1.0, src/EventLoop/Internal/ClosureHelper.php.
// MIT License; copyright notices in LICENSE.

namespace Fledge\Loop\Internal;

/** @internal */
final class ClosureHelper
{
    public static function getDescription(\Closure $closure): string
    {
        try {
            $reflection = new \ReflectionFunction($closure);

            $description = $reflection->name;

            if ($scopeClass = $reflection->getClosureScopeClass()) {
                $description = $scopeClass->name . '::' . $description;
            }

            if ($reflection->getFileName() !== false && $reflection->getStartLine()) {
                $description .= " defined in " . $reflection->getFileName() . ':' . $reflection->getStartLine();
            }

            return $description;
        } catch (\ReflectionException) {
            return '???';
        }
    }
}
