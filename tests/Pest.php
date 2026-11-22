<?php

declare(strict_types=1);

use Fledge\Loop;
use Fledge\Loop\DriverFactory;

require_once __DIR__.'/Support/subprocess.php';

// Select the process-wide event loop driver for the suite (CI matrix leg FLEDGE_EVENT_LOOP=poll|select). The lazily
// created default driver honours the variable too; installing it here makes an invalid value fail before any test.
// Pest loads this file once per process, before any test touches the loop.
if (is_string($fledgeEventLoop = getenv(DriverFactory::ENV)) && trim($fledgeEventLoop) !== '') {
    Loop::install();
}

unset($fledgeEventLoop);
