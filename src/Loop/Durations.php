<?php

declare(strict_types=1);

namespace Fledge\Loop;

use Time\Duration;

/**
 * Conversions between PHP 8.6's `Time\Duration` and the float seconds the loop works with internally.
 *
 * Every conversion from float rounds up (ceil), so nothing waits shorter than asked: 0.0000000015 seconds is
 * 2 nanoseconds, never 1. This is the rule `PollDriver` applies inline before each kernel wait. A float is multiplied
 * first and rounded up after, so a decimal literal keeps its intended value (1.1 seconds is 1,100,000,000
 * nanoseconds); the result is exact to the nanosecond while the product stays below 2^53 nanoseconds (about 104 days),
 * beyond that the float itself is coarser than a nanosecond.
 *
 * A `Duration` holds at most 9,223,372,035 seconds and 999,999,999 nanoseconds (roughly 292 years); its factories
 * reject negative input, so only `negate()` or `sub()` yield a negative one. The loop's own range is wider: float INF
 * is a timer that never fires, and null is "no timeout". Both stay floats or null here.
 *
 * Negative input is handled per method: `fromSeconds()` rejects it, the `to*()` methods keep the sign. A loop entry
 * point that takes `Duration|float` converts with `toSeconds()` and keeps its existing `!($delay >= 0)` guard: a
 * negated `Duration` becomes a negative float there and raises the same `\Error` as a negative float.
 */
final class Durations
{
    /** The largest whole-second count a `Duration` holds. */
    private const int MAX_SECONDS = 9_223_372_035;

    /** The first nanosecond count a `Duration` cannot hold, exactly representable as a float. */
    private const float NANOSECONDS_LIMIT = 9_223_372_036_000_000_000.0;

    /** 2^63 as a float: the first value above PHP_INT_MAX. */
    private const float INT_LIMIT = 9_223_372_036_854_775_808.0;

    private function __construct() {}

    /**
     * Seconds to a `Duration`, rounded up to the next nanosecond.
     *
     * INF and any value above the `Duration` range (more than 9,223,372,035.999999999 seconds) return null, meaning
     * no limit. -0.0 is zero. NAN and negative values throw the `\Error` the loop throws for an invalid delay; pass
     * `$name` ('Interval', 'Timeout') to name the argument in that message.
     *
     * @throws \Error For NAN or a negative value.
     */
    public static function fromSeconds(float|int $seconds, string $name = 'Delay'): ?Duration
    {
        if (\is_int($seconds)) {
            if ($seconds < 0) {
                throw new \Error("{$name} must be greater than or equal to zero");
            }

            return $seconds > self::MAX_SECONDS ? null : Duration::fromSeconds($seconds);
        }

        // NAN fails every comparison, so it lands here together with negative values.
        if (! ($seconds >= 0)) {
            throw new \Error(\is_nan($seconds) ? "{$name} must be a number, NAN given" : "{$name} must be greater than or equal to zero");
        }

        // INF * 1e9 is INF, which is caught by the same bound.
        $nanoseconds = \ceil($seconds * 1_000_000_000);

        if ($nanoseconds >= self::NANOSECONDS_LIMIT) {
            return null;
        }

        return Duration::fromNanoseconds((int) $nanoseconds);
    }

    /**
     * Seconds as a float. Never throws.
     *
     * A negated `Duration` gives a negative float. Float input is returned as is, INF, -INF and NAN included; int
     * input is cast. The `Duration` maximum reads back as 9223372036.0, which `fromSeconds()` treats as over the range.
     */
    public static function toSeconds(Duration|float|int $duration): float
    {
        if ($duration instanceof Duration) {
            $seconds = $duration->seconds + $duration->nanoseconds / 1e9;

            return $duration->negative ? -$seconds : $seconds;
        }

        return (float) $duration;
    }

    /**
     * Whole seconds, rounded up (towards positive infinity). Sign, saturation and NAN as in `toMilliseconds()`; int
     * input passes through.
     *
     * A `Duration` is converted with integer maths, so any nanosecond fraction adds a second (200,000,000 seconds
     * plus 1 nanosecond is 200,000,001), which a float `ceil()` loses above about 1e8 seconds. A negated `Duration`
     * drops its fraction (-1.5 seconds is -1). Nothing is clamped to zero: that is the caller's policy.
     *
     * @throws \Error For NAN.
     */
    public static function toWholeSeconds(Duration|float|int $duration): int
    {
        return self::scale($duration, 1);
    }

    /**
     * Whole milliseconds, rounded up (towards positive infinity).
     *
     * The sign is kept: a negated `Duration` or a negative number gives a negative count (-1.5 ms is -1), so the
     * caller checks `< 0`. INF and anything above PHP_INT_MAX saturate to PHP_INT_MAX, -INF and anything below
     * PHP_INT_MIN to PHP_INT_MIN.
     *
     * @throws \Error For NAN.
     */
    public static function toMilliseconds(Duration|float|int $duration): int
    {
        return self::scale($duration, 1_000);
    }

    /**
     * Whole microseconds, rounded up (towards positive infinity). Sign, saturation and NAN as in `toMilliseconds()`.
     *
     * @throws \Error For NAN.
     */
    public static function toMicroseconds(Duration|float|int $duration): int
    {
        return self::scale($duration, 1_000_000);
    }

    /**
     * Whole nanoseconds, rounded up (towards positive infinity). Sign, saturation and NAN as in `toMilliseconds()`.
     * Every `Duration` fits: its maximum is 9,223,372,035,999,999,999 nanoseconds.
     *
     * @throws \Error For NAN.
     */
    public static function toNanoseconds(Duration|float|int $duration): int
    {
        return self::scale($duration, 1_000_000_000);
    }

    /**
     * Seconds as a float, with null kept as "no timeout". Otherwise identical to `toSeconds()`.
     */
    public static function nullable(Duration|float|int|null $duration): ?float
    {
        return $duration === null ? null : self::toSeconds($duration);
    }

    /**
     * @param  1|1000|1000000|1000000000  $perSecond
     */
    private static function scale(Duration|float|int $duration, int $perSecond): int
    {
        if ($duration instanceof Duration) {
            // No overflow: 9,223,372,035 seconds times 1e9 plus the fraction stays below PHP_INT_MAX.
            $divisor = \intdiv(1_000_000_000, $perSecond);
            $fraction = $duration->negative
                ? \intdiv($duration->nanoseconds, $divisor)
                : \intdiv($duration->nanoseconds + $divisor - 1, $divisor);
            $magnitude = $duration->seconds * $perSecond + $fraction;

            return $duration->negative ? -$magnitude : $magnitude;
        }

        if (\is_int($duration)) {
            return match (true) {
                $duration > \intdiv(\PHP_INT_MAX, $perSecond) => \PHP_INT_MAX,
                $duration < \intdiv(\PHP_INT_MIN, $perSecond) => \PHP_INT_MIN,
                default => $duration * $perSecond,
            };
        }

        if (\is_nan($duration)) {
            throw new \Error('Duration must be a number, NAN given');
        }

        $scaled = \ceil($duration * $perSecond);

        return match (true) {
            $scaled >= self::INT_LIMIT => \PHP_INT_MAX,
            $scaled <= -self::INT_LIMIT => \PHP_INT_MIN,
            default => (int) $scaled,
        };
    }
}
