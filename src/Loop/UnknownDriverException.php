<?php

declare(strict_types=1);

namespace Fledge\Loop;

/**
 * Thrown by {@see DriverFactory} (and with it {@see \Fledge\Loop::install()}, {@see \Fledge\Loop::afterFork()},
 * {@see \Fledge\Loop::fork()} and the lazily created default driver) for a driver name, from the argument or from
 * `FLEDGE_EVENT_LOOP`, that is not `auto`, `poll` or `select`. Nothing is changed when it is thrown.
 *
 * It extends \InvalidArgumentException, so a `catch (\InvalidArgumentException)` also catches it.
 */
final class UnknownDriverException extends \InvalidArgumentException
{
}
