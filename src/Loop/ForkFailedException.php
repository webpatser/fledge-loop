<?php

declare(strict_types=1);

namespace Fledge\Loop;

/**
 * Thrown by {@see \Fledge\Loop::fork()} when `pcntl_fork()` fails (for example EAGAIN at the process limit). No child
 * was created and the parent's loop is unchanged; the message carries the error `pcntl_strerror()` reports.
 *
 * It extends \RuntimeException, so a `catch (\RuntimeException)` also catches it.
 */
final class ForkFailedException extends \RuntimeException
{
}
