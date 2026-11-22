<?php

declare(strict_types=1);

namespace Fledge\Loop\Tests\Fixtures;

/**
 * A user-space stream wrapper whose stream_cast() hands out an inner stream ({@see self::$inner}), as a wrapper around
 * a file or a pipe does. With {@see self::$claimRegularFile} its stream_stat() reports a regular file whatever the
 * inner stream is (a wrapper that misreports its descriptor).
 */
final class CastingStreamWrapper
{
    /** @var resource|null The stream the next opened wrapper casts to. */
    public static mixed $inner = null;

    public static bool $claimRegularFile = false;

    /** @var resource|null */
    public mixed $context = null;

    /** @var resource */
    private mixed $stream;

    public static function register(): void
    {
        if (!\in_array('fledge-cast', \stream_get_wrappers(), true)) {
            \stream_wrapper_register('fledge-cast', self::class);
        }

        // The same wrapper registered as a URL wrapper, which stream_is_local() reports as not local.
        if (!\in_array('fledge-cast-url', \stream_get_wrappers(), true)) {
            \stream_wrapper_register('fledge-cast-url', self::class, \STREAM_IS_URL);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (!\is_resource(self::$inner)) {
            return false;
        }

        $this->stream = self::$inner;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        return \fread($this->stream, $count);
    }

    public function stream_eof(): bool
    {
        return \feof($this->stream);
    }

    /**
     * @return resource
     */
    public function stream_cast(int $castAs): mixed
    {
        return $this->stream;
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        $stat = \fstat($this->stream);

        if (self::$claimRegularFile && \is_array($stat)) {
            $stat['mode'] = 0100644;
            $stat[2] = 0100644;
        }

        return $stat;
    }
}
