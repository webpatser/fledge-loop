<?php

declare(strict_types=1);

use Fledge\Loop\DriverFactory;

/**
 * Runs $code in a fresh PHP process with the package autoloader loaded, and returns its output and exit status.
 *
 * The child gets the parent's zend.assertions and assert.exception settings, `display_errors=stderr`, the parent's
 * environment without FLEDGE_EVENT_LOOP (a scenario installs the driver it tests) plus $env, and STDIN from /dev/null
 * unless $stdin names another file. It is killed after $timeout seconds, so a scenario that hangs fails its test instead of
 * the suite; `timedOut` reports that. The child runs in its own process group, and the timeout kills that group, so
 * a hung process it forked is not left behind.
 *
 * @param  array<string, string>  $env  Extra environment variables.
 * @param  array<string, string>  $ini  ini settings for the child, applied after (and so overriding) the defaults above.
 * @return array{stdout: string, stderr: string, exit: int, timedOut: bool}
 */
function runPhpSubprocess(string $code, array $env = [], float $timeout = 60.0, string $stdin = '/dev/null', array $ini = []): array
{
    $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
    // Its own process group, so a timeout also kills the processes it forked.
    $prelude = "if (function_exists('posix_setpgid')) { posix_setpgid(0, 0); }\n"
        .'require '.var_export($autoload, true).";\n";

    $processEnv = getenv();
    unset($processEnv[DriverFactory::ENV]);

    $command = [
        PHP_BINARY,
        '-d', 'display_errors=stderr',
        '-d', 'zend.assertions='.ini_get('zend.assertions'),
        '-d', 'assert.exception='.ini_get('assert.exception'),
    ];

    foreach ($ini as $name => $value) {
        $command[] = '-d';
        $command[] = $name.'='.$value;
    }

    $command[] = '-r';
    $command[] = $prelude.$code;

    $process = proc_open($command, [0 => ['file', $stdin, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + $processEnv);

    if (! is_resource($process)) {
        throw new RuntimeException('proc_open() failed');
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = [1 => '', 2 => ''];
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    $deadline = hrtime(true) + (int) ($timeout * 1_000_000_000);
    $timedOut = false;

    while ($open !== []) {
        $left = $deadline - hrtime(true);

        if ($left <= 0) {
            $timedOut = true;
            $pid = proc_get_status($process)['pid'];

            // The whole group (the child and anything it forked); if the child had not yet made its own group, only
            // the child itself.
            if (! function_exists('posix_kill') || ! @posix_kill(-$pid, 9)) {
                proc_terminate($process, 9);
            }

            break;
        }

        $read = array_values($open);
        $write = $except = null;

        if (@stream_select($read, $write, $except, intdiv($left, 1_000_000_000), intdiv($left % 1_000_000_000, 1000)) === false) {
            continue; // EINTR
        }

        foreach ($open as $fd => $pipe) {
            if (! in_array($pipe, $read, true)) {
                continue;
            }

            $chunk = fread($pipe, 65536);

            if ($chunk === '' || $chunk === false) {
                if (feof($pipe)) {
                    unset($open[$fd]);
                }

                continue;
            }

            $output[$fd] .= $chunk;
        }
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return ['stdout' => $output[1], 'stderr' => $output[2], 'exit' => $exit, 'timedOut' => $timedOut];
}
