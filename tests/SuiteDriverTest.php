<?php

declare(strict_types=1);

use Fledge\Loop;
use Fledge\Loop\Driver\PollDriver;
use Fledge\Loop\Driver\StreamSelectDriver;
use Fledge\Loop\DriverFactory;

/*
 * Guard: the suite must really run on the driver FLEDGE_EVENT_LOOP selects (or on `auto` when it is unset). Fails
 * loudly if the bootstrap in tests/Pest.php stops installing it, or if the lazily created default driver stops
 * honouring the variable.
 */
$resolved = DriverFactory::resolve();

test('the suite runs on the event loop driver selected by FLEDGE_EVENT_LOOP', function () use ($resolved) {
    $expected = match ($resolved) {
        'poll' => PollDriver::class,
        'select' => StreamSelectDriver::class,
    };

    expect(Loop::getDriver())->toBeInstanceOf($expected);
});
