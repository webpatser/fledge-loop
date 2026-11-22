<?php

declare(strict_types=1);

// Analysis-only stand-ins for the ext-fiberio constants FiberIoWaiter uses, with the values of php_fiberio.h, so
// PHPStan can resolve them on a machine without the extension. Never loaded at runtime.

if (!\defined('FiberIo\READABLE')) {
    \define('FiberIo\READABLE', 1);
}

if (!\defined('FiberIo\WRITABLE')) {
    \define('FiberIo\WRITABLE', 2);
}
