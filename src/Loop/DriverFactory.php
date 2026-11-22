<?php

declare(strict_types=1);

// Derived from revolt/event-loop v1.1.0, src/EventLoop/DriverFactory.php.
// MIT License; copyright notices in LICENSE.

namespace Fledge\Loop;

// @codeCoverageIgnoreStart
use Fledge\Loop\Driver\PollDriver;
use Fledge\Loop\Driver\StreamSelectDriver;

/**
 * Chooses the event loop driver by name.
 *
 * The name comes from the argument, or from the `FLEDGE_EVENT_LOOP` environment variable when the argument is null
 * (default `auto`). Matching ignores case and surrounding whitespace; an empty value means `auto`.
 *
 * - `auto`: {@see PollDriver} when {@see PollDriver::isSupported()} (kqueue or epoll), otherwise
 *   {@see StreamSelectDriver}. Never throws for lack of a backend.
 * - `poll`: {@see PollDriver}; throws {@see UnsupportedFeatureException} when neither kqueue nor epoll is available.
 *   An explicit `poll` never falls back silently.
 * - `select`: {@see StreamSelectDriver}, always. This is the rollback value.
 *
 * Any other value throws an {@see UnknownDriverException} (an \InvalidArgumentException) naming the accepted values.
 * The loop's lazily created default driver ({@see \Fledge\Loop::getDriver()}) goes through this factory too, so the
 * environment variable is honoured however the loop is first used, and an invalid value fails at that first use.
 */
final class DriverFactory
{
    public const string ENV = 'FLEDGE_EVENT_LOOP';

    /** @var list<string> */
    private const array NAMES = ['auto', 'poll', 'select'];

    /**
     * Creates a new, uninstalled driver.
     *
     * @param string|null $name `auto`, `poll` or `select`; null reads `FLEDGE_EVENT_LOOP` (default `auto`).
     *
     * @throws UnknownDriverException When the name is not one of the accepted values (an \InvalidArgumentException).
     * @throws UnsupportedFeatureException When `poll` is requested but neither kqueue nor epoll is available.
     */
    public function create(?string $name = null): Driver
    {
        return match (self::resolve($name)) {
            'poll' => new PollDriver(),
            'select' => new StreamSelectDriver(),
        };
    }

    /**
     * The effective driver name, `poll` or `select`, with `auto` resolved. Validates without creating anything.
     *
     * @param string|null $name `auto`, `poll` or `select`; null reads `FLEDGE_EVENT_LOOP` (default `auto`).
     *
     * @return 'poll'|'select'
     *
     * @throws UnknownDriverException When the name is not one of the accepted values (an \InvalidArgumentException).
     * @throws UnsupportedFeatureException When `poll` is requested but neither kqueue nor epoll is available.
     */
    public static function resolve(?string $name = null): string
    {
        if ($name === null) {
            $fromEnvironment = \getenv(self::ENV);
            $name = \is_string($fromEnvironment) ? $fromEnvironment : 'auto';
        }

        $normalised = \strtolower(\trim($name));

        return match ($normalised) {
            '', 'auto' => PollDriver::isSupported() ? 'poll' : 'select',
            'poll' => PollDriver::isSupported() ? 'poll' : throw new UnsupportedFeatureException(\sprintf(
                'Event loop driver "poll" was requested but neither kqueue nor epoll is available; set %s=select to roll back.',
                self::ENV,
            )),
            'select' => 'select',
            default => throw new UnknownDriverException(\sprintf(
                'Unknown event loop driver "%s" (%s); expected one of: %s.',
                $name,
                self::ENV,
                \implode(', ', self::NAMES),
            )),
        };
    }
}
// @codeCoverageIgnoreEnd
