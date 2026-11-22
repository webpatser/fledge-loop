<?php

declare(strict_types=1);

/*
 * Ported from revolt/event-loop v1.1.0, test/event_loop_destruction_order_stream_select.phpt (MIT).
 * Ported from revolt/event-loop v1.1.0, test/event_loop_destruction_order_io_poll.phpt (MIT).
 * The io_poll case is retargeted to PollDriver.
 *
 * Revolt issue #105: the callback fiber stays alive as long as the event loop lives. An object held in a static
 * property is destroyed during PHP shutdown, after Loop::run() returned, and its destructor can still suspend on the
 * loop and be resumed by a timer. Upstream runs these as .phpt files; here each runs in a child PHP process that must
 * print exactly the expected output and exit with 0.
 */

const DESTRUCTION_ORDER_SCENARIO = <<<'PHP'
    use Fledge\Loop;

    Loop::setDriver($driver);

    final class a {
        private static self $a;
        public static function getInstance(): self {
            return self::$a ??= new self;
        }

        public function __destruct()
        {
            echo "Destroying ", self::class, "\n";
            $suspension = Loop::getSuspension();
            Loop::delay(1.0, $suspension->resume(...));
            $suspension->suspend();
            echo "Finished " . self::class, "\n";
        }
    }

    Loop::defer(function () {
        echo "start\n";
    });

    a::getInstance();

    Loop::run();
    PHP;

it('keeps the callback fiber alive for a destructor that suspends during shutdown', function (string $driver) {
    $result = runPhpSubprocess('$driver = new '.$driver."();\n".DESTRUCTION_ORDER_SCENARIO, timeout: 20.0);

    expect($result['timedOut'])->toBeFalse()
        ->and($result['stdout'])->toBe("start\nDestroying a\nFinished a\n", "stderr: {$result['stderr']}")
        ->and($result['stderr'])->toBe('')
        ->and($result['exit'])->toBe(0);
})->with([
    'stream_select' => Fledge\Loop\Driver\StreamSelectDriver::class,
    'io_poll' => Fledge\Loop\Driver\PollDriver::class,
]);
