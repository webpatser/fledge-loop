<?php

declare(strict_types=1);

use Fledge\Loop\Durations;
use Time\Duration;

/*
 * Durations converts between Time\Duration and the loop's float seconds. Conversions from float always round up, so
 * nothing waits shorter than asked. A Duration holds at most 9,223,372,035 seconds and 999,999,999 nanoseconds.
 */

function durationParts(?Duration $duration): ?array
{
    return $duration === null ? null : [$duration->seconds, $duration->nanoseconds, $duration->negative];
}

describe('fromSeconds', function () {
    it('rounds a float up to the next nanosecond', function (float $seconds, array $parts) {
        expect(durationParts(Durations::fromSeconds($seconds)))->toBe($parts);
    })->with([
        '1.5 ns is 2, never 1' => [0.0000000015, [0, 2, false]],
        '1 ns' => [0.000000001, [0, 1, false]],
        'subnormal is 1 ns' => [5e-324, [0, 1, false]],
        '1 ms' => [0.001, [0, 1_000_000, false]],
        '0.1 s keeps its decimal value' => [0.1, [0, 100_000_000, false]],
        '1.1 s keeps its decimal value' => [1.1, [1, 100_000_000, false]],
        '1.5 s' => [1.5, [1, 500_000_000, false]],
        'zero' => [0.0, [0, 0, false]],
        '-0.0 is zero' => [-0.0, [0, 0, false]],
    ]);

    it('takes whole seconds as int', function () {
        expect(durationParts(Durations::fromSeconds(0)))->toBe([0, 0, false])
            ->and(durationParts(Durations::fromSeconds(30)))->toBe([30, 0, false]);
    });

    it('returns null for INF, meaning no limit', function () {
        expect(Durations::fromSeconds(INF))->toBeNull();
    });

    it('returns a Duration up to the top of its range and null above it', function (float|int $seconds, ?array $parts) {
        expect(durationParts(Durations::fromSeconds($seconds)))->toBe($parts);
    })->with([
        'int maximum' => [9_223_372_035, [9_223_372_035, 0, false]],
        'int one above' => [9_223_372_036, null],
        'PHP_INT_MAX' => [PHP_INT_MAX, null],
        // The largest float whose nanosecond product Duration still holds.
        'float just below the limit' => [9_223_372_035.999999, [9_223_372_035, 999_997_952, false]],
        'float at the limit' => [9_223_372_036.0, null],
        'float far above' => [1e300, null],
    ]);

    it('rejects NAN and negative values with the loop delay messages', function (float|int $seconds, string $message) {
        expect(fn () => Durations::fromSeconds($seconds))->toThrow(Error::class, $message);
    })->with([
        'NAN' => [NAN, 'Delay must be a number, NAN given'],
        'negative float' => [-0.001, 'Delay must be greater than or equal to zero'],
        '-INF' => [-INF, 'Delay must be greater than or equal to zero'],
        'negative int' => [-1, 'Delay must be greater than or equal to zero'],
    ]);

    it('names the argument in its messages', function () {
        expect(fn () => Durations::fromSeconds(NAN, 'Interval'))->toThrow(Error::class, 'Interval must be a number, NAN given')
            ->and(fn () => Durations::fromSeconds(-1, 'Timeout'))->toThrow(Error::class, 'Timeout must be greater than or equal to zero');
    });
});

describe('toSeconds', function () {
    it('converts a Duration', function () {
        expect(Durations::toSeconds(Duration::fromSeconds(1, 500_000_000)))->toBe(1.5)
            ->and(Durations::toSeconds(Duration::fromNanoseconds(1)))->toBe(1e-9)
            ->and(Durations::toSeconds(Duration::fromSeconds(0)))->toBe(0.0);
    });

    it('gives a negated Duration a negative sign, which the loop guard rejects', function () {
        $seconds = Durations::toSeconds(Duration::fromSeconds(1, 500_000_000)->negate());

        expect($seconds)->toBe(-1.5)
            ->and($seconds >= 0)->toBeFalse()
            ->and(fn () => Durations::fromSeconds($seconds))->toThrow(Error::class, 'Delay must be greater than or equal to zero');
    });

    it('passes floats through and casts ints', function () {
        expect(Durations::toSeconds(0.25))->toBe(0.25)
            ->and(Durations::toSeconds(-1.5))->toBe(-1.5)
            ->and(Durations::toSeconds(INF))->toBe(INF)
            ->and(Durations::toSeconds(-INF))->toBe(-INF)
            ->and(is_nan(Durations::toSeconds(NAN)))->toBeTrue()
            ->and(Durations::toSeconds(3))->toBe(3.0);
    });

    it('round-trips through fromSeconds', function (float $seconds) {
        expect(Durations::toSeconds(Durations::fromSeconds($seconds)))->toBe($seconds);
    })->with([0.0, 0.001, 0.1, 1.1, 1.5, 30.0, 86_400.25]);

    it('reads the Duration maximum back as a float over the range', function () {
        $seconds = Durations::toSeconds(Duration::fromSeconds(9_223_372_035, 999_999_999));

        expect($seconds)->toBe(9_223_372_036.0)
            ->and(Durations::fromSeconds($seconds))->toBeNull();
    });
});

describe('integer units', function () {
    it('rounds float input up', function (string $method, float $seconds, int $expected) {
        expect(Durations::{$method}($seconds))->toBe($expected);
    })->with([
        'ms 1.5 ms is 2' => ['toMilliseconds', 0.0015, 2],
        'ms 1.5 s' => ['toMilliseconds', 1.5, 1_500],
        'ms 1 ns is 1' => ['toMilliseconds', 0.000000001, 1],
        'us 1.5 us is 2' => ['toMicroseconds', 0.0000015, 2],
        'us 1.5 s' => ['toMicroseconds', 1.5, 1_500_000],
        'ns 1.5 ns is 2, never 1' => ['toNanoseconds', 0.0000000015, 2],
        'ns 1.1 s' => ['toNanoseconds', 1.1, 1_100_000_000],
        'ns -0.0 is zero' => ['toNanoseconds', -0.0, 0],
        'ms negative rounds towards zero' => ['toMilliseconds', -0.0015, -1],
    ]);

    it('rounds a Duration up', function (string $method, Duration $duration, int $expected) {
        expect(Durations::{$method}($duration))->toBe($expected);
    })->with([
        'ms 1 ns is 1' => ['toMilliseconds', Duration::fromNanoseconds(1), 1],
        'ms exact' => ['toMilliseconds', Duration::fromSeconds(2, 5_000_000), 2_005],
        'us 1,000,001 ns is 1,001' => ['toMicroseconds', Duration::fromNanoseconds(1_000_001), 1_001],
        'ns exact' => ['toNanoseconds', Duration::fromSeconds(1, 500_000_000), 1_500_000_000],
        'ns maximum fits' => ['toNanoseconds', Duration::fromSeconds(9_223_372_035, 999_999_999), 9_223_372_035_999_999_999],
        'ms zero' => ['toMilliseconds', Duration::fromSeconds(0), 0],
    ]);

    it('keeps the sign of a negated Duration, rounding towards positive infinity', function (string $method, Duration $duration, int $expected) {
        expect(Durations::{$method}($duration->negate()))->toBe($expected);
    })->with([
        'ms -1.5 ms is -1' => ['toMilliseconds', Duration::fromNanoseconds(1_500_000), -1],
        'ms -1 ns is 0' => ['toMilliseconds', Duration::fromNanoseconds(1), 0],
        'us -1.5 s' => ['toMicroseconds', Duration::fromSeconds(1, 500_000_000), -1_500_000],
        'ns -1 s' => ['toNanoseconds', Duration::fromSeconds(1), -1_000_000_000],
        'ns negated maximum' => ['toNanoseconds', Duration::fromSeconds(9_223_372_035, 999_999_999), -9_223_372_035_999_999_999],
    ]);

    it('scales int input', function () {
        expect(Durations::toMilliseconds(2))->toBe(2_000)
            ->and(Durations::toMicroseconds(-3))->toBe(-3_000_000)
            ->and(Durations::toNanoseconds(1))->toBe(1_000_000_000);
    });

    it('saturates INF and out-of-range values', function (string $method, float|int $seconds, int $expected) {
        expect(Durations::{$method}($seconds))->toBe($expected);
    })->with([
        'ms INF' => ['toMilliseconds', INF, PHP_INT_MAX],
        'us -INF' => ['toMicroseconds', -INF, PHP_INT_MIN],
        'ns huge float' => ['toNanoseconds', 1e300, PHP_INT_MAX],
        'ns huge negative float' => ['toNanoseconds', -1e300, PHP_INT_MIN],
        'ns PHP_INT_MAX seconds' => ['toNanoseconds', PHP_INT_MAX, PHP_INT_MAX],
        'ms PHP_INT_MIN seconds' => ['toMilliseconds', PHP_INT_MIN, PHP_INT_MIN],
    ]);

    it('rejects NAN', function (string $method) {
        expect(fn () => Durations::{$method}(NAN))->toThrow(Error::class, 'Duration must be a number, NAN given');
    })->with(['toMilliseconds', 'toMicroseconds', 'toNanoseconds']);

    it('round-trips through fromSeconds', function (float $seconds, int $milliseconds, int $microseconds, int $nanoseconds) {
        $duration = Durations::fromSeconds($seconds);

        expect(Durations::toMilliseconds($duration))->toBe($milliseconds)
            ->and(Durations::toMicroseconds($duration))->toBe($microseconds)
            ->and(Durations::toNanoseconds($duration))->toBe($nanoseconds)
            ->and(Durations::toMilliseconds($seconds))->toBe($milliseconds)
            ->and(Durations::toMicroseconds($seconds))->toBe($microseconds)
            ->and(Durations::toNanoseconds($seconds))->toBe($nanoseconds);
    })->with([
        '1 ms' => [0.001, 1, 1_000, 1_000_000],
        '1.5 s' => [1.5, 1_500, 1_500_000, 1_500_000_000],
        '30 s' => [30.0, 30_000, 30_000_000, 30_000_000_000],
        '1 ns' => [0.000000001, 1, 1, 1],
    ]);

    it('round-trips each unit back into a Duration', function () {
        $duration = Duration::fromSeconds(12, 345_678_000);

        expect(durationParts(Duration::fromMilliseconds(Durations::toMilliseconds($duration))))->toBe([12, 346_000_000, false])
            ->and(durationParts(Duration::fromMicroseconds(Durations::toMicroseconds($duration))))->toBe([12, 345_678_000, false])
            ->and(durationParts(Duration::fromNanoseconds(Durations::toNanoseconds($duration))))->toBe([12, 345_678_000, false]);
    });

    it('rounds whole seconds up, keeping the sign', function (Duration|float|int $duration, int $expected) {
        expect(Durations::toWholeSeconds($duration))->toBe($expected);
    })->with([
        '1 ns is 1' => [Duration::fromNanoseconds(1), 1],
        '1500 ms is 2' => [Duration::fromMilliseconds(1_500), 2],
        'exactly 2 s' => [Duration::fromSeconds(2), 2],
        'zero' => [Duration::fromSeconds(0), 0],
        'negated 1500 ms is -1' => [Duration::fromMilliseconds(1_500)->negate(), -1],
        'negated 2 s' => [Duration::fromSeconds(2)->negate(), -2],
        // A float ceil() loses the 1 ns here; the integer maths keeps it.
        '200,000,000 s plus 1 ns' => [Duration::fromSeconds(200_000_000, 1), 200_000_001],
        'float 0.2 is 1' => [0.2, 1],
        'float -1.5 is -1' => [-1.5, -1],
        'int passes through' => [42, 42],
        'negative int passes through' => [-7, -7],
    ]);

    it('saturates INF whole seconds and rejects NAN', function () {
        expect(Durations::toWholeSeconds(INF))->toBe(PHP_INT_MAX)
            ->and(Durations::toWholeSeconds(-INF))->toBe(PHP_INT_MIN)
            ->and(Durations::toWholeSeconds(1e300))->toBe(PHP_INT_MAX)
            ->and(fn () => Durations::toWholeSeconds(NAN))->toThrow(Error::class, 'Duration must be a number, NAN given');
    });
});

describe('nullable', function () {
    it('keeps null as no timeout and converts everything else to seconds', function () {
        expect(Durations::nullable(null))->toBeNull()
            ->and(Durations::nullable(Duration::fromMilliseconds(250)))->toBe(0.25)
            ->and(Durations::nullable(Duration::fromSeconds(2)->negate()))->toBe(-2.0)
            ->and(Durations::nullable(1.5))->toBe(1.5)
            ->and(Durations::nullable(0))->toBe(0.0)
            ->and(Durations::nullable(INF))->toBe(INF);
    });
});

it('cannot be instantiated', function () {
    $constructor = new ReflectionMethod(Durations::class, '__construct');

    expect($constructor->isPrivate())->toBeTrue()
        ->and((new ReflectionClass(Durations::class))->isFinal())->toBeTrue();
});
