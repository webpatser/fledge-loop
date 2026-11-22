<?php

declare(strict_types=1);

namespace Fledge\Loop;

/**
 * Thrown by {@see \Fledge\Loop::afterFork()} when fibers inherited from the parent threw while unwinding in the forked
 * child. The fresh driver is already installed and usable: the errors belong to the parent's abandoned work, and
 * catching this exception and carrying on is safe. The first error is the previous exception;
 * {@see self::getUnwindErrors()} lists every error caught, in order. The fibers unwind during garbage collection, and
 * PHP chains the exceptions thrown within one collection pass through getPrevious(), so one entry can stand for
 * several fibers.
 *
 * It extends \RuntimeException, so a `catch (\RuntimeException)` also catches it.
 */
final class ForkUnwindException extends \RuntimeException
{
    /**
     * @param non-empty-list<\Throwable> $unwindErrors
     */
    public function __construct(string $message, private readonly array $unwindErrors)
    {
        parent::__construct($message, previous: $unwindErrors[0]);
    }

    /**
     * The exceptions caught while the inherited fibers unwound, one per collection pass that threw (follow each
     * one's getPrevious() for the others of that pass); the first one is the previous exception.
     *
     * @return non-empty-list<\Throwable>
     */
    public function getUnwindErrors(): array
    {
        return $this->unwindErrors;
    }
}
