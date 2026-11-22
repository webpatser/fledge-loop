<?php

declare(strict_types=1);

namespace Fledge\Loop;

/**
 * Thrown when the event loop's state does not allow the requested operation, before anything was changed unless the
 * message says otherwise: {@see \Fledge\Loop::install()} while the loop is running or has callbacks registered;
 * {@see \Fledge\Loop::afterFork()} or {@see \Fledge\Loop::fork()} inside a fiber or with a third-party driver;
 * afterFork() outside a forked child, or while the inherited Io\Poll\Context is still referenced (after the fresh
 * driver was installed); and a PollDriver used in a forked child or after it was abandoned.
 *
 * It extends \LogicException, so a `catch (\LogicException)` also catches it. Catch this class to tell these refusals
 * apart from an {@see UnknownDriverException}, which is an \InvalidArgumentException and therefore a \LogicException
 * too.
 */
final class LoopStateException extends \LogicException
{
}
