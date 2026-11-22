<?php

declare(strict_types=1);

/*
 * Proof of the faithful port: reverses the mechanical rename on every file derived from revolt/event-loop v1.1.0
 * and diffs it against the original, printing the remaining differences per file.
 *
 * Usage: php tools/verify-port.php <path to revolt/event-loop v1.1.0 src/> [--quiet]
 *
 * Get the original with: git clone --depth 1 --branch v1.1.0 https://github.com/revoltphp/event-loop
 *
 * Exits 1 when a derived file has no original (or the header is missing), 0 otherwise. Remaining hunks are
 * expected and listed, not judged: review them against the port's allowed differences.
 */

$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => $a !== '--quiet'));
$quiet = in_array('--quiet', $argv, true);

if (count($args) !== 1 || !is_dir($args[0])) {
    fwrite(STDERR, "Usage: php tools/verify-port.php <revolt/event-loop v1.1.0 src dir> [--quiet]\n");
    exit(2);
}

$original = rtrim($args[0], '/');
$root = dirname(__DIR__);
$header = "/^\\/\\/ Derived from revolt\\/event-loop v1\\.1\\.0, src\\/(\\S+)\\.\\n\\/\\/ MIT License; copyright notices in LICENSE\\.\\n\\n/m";

$files = [$root . '/src/Loop.php'];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src/Loop', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getExtension() === 'php') {
        $files[] = $file->getPathname();
    }
}
sort($files);

$tmp = sys_get_temp_dir() . '/fledge-loop-verify-' . getmypid();
@mkdir($tmp, 0700, true);

$failed = false;
$ported = [];
$derived = 0;
$total = 0;

foreach ($files as $path) {
    $relative = substr($path, strlen($root) + 1);
    $source = (string) file_get_contents($path);

    if (!preg_match($header, $source, $match)) {
        if (!$quiet) {
            echo "== {$relative}: Fledge-authored (no derived header), skipped\n";
        }

        continue;
    }

    $derived++;
    $ported[$match[1]] = true;
    $originalPath = $original . '/' . $match[1];

    if (!is_file($originalPath)) {
        echo "== {$relative}: original src/{$match[1]} not found\n";
        $failed = true;

        continue;
    }

    $reversed = preg_replace($header, '', $source, 1);
    $reversed = str_replace('Fledge\\Loop', 'Revolt\\EventLoop', $reversed);
    $reversed = preg_replace('/^namespace Fledge;$/m', 'namespace Revolt;', $reversed);
    $reversed = preg_replace('/^final class Loop$/m', 'final class EventLoop', $reversed);
    $reversed = preg_replace('/(?<![\\w\\\\])Loop::/', 'EventLoop::', $reversed);

    $reversedPath = $tmp . '/' . str_replace('/', '_', $relative);
    file_put_contents($reversedPath, $reversed);

    $diff = (string) shell_exec(sprintf(
        'diff -u --label %s --label %s %s %s',
        escapeshellarg('revolt/src/' . $match[1]),
        escapeshellarg('fledge-loop/' . $relative . ' (rename reversed)'),
        escapeshellarg($originalPath),
        escapeshellarg($reversedPath),
    ));
    unlink($reversedPath);

    $hunks = preg_match_all('/^@@ /m', $diff);
    $total += $hunks;

    echo "== {$relative} <- src/{$match[1]}: {$hunks} hunk(s) besides the header\n";

    if ($hunks > 0 && !$quiet) {
        echo $diff;
    }
}

@rmdir($tmp);

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($original, FilesystemIterator::SKIP_DOTS));
$leftOut = [];
foreach ($iterator as $file) {
    $name = substr($file->getPathname(), strlen($original) + 1);
    if ($file->getExtension() === 'php' && !isset($ported[$name])) {
        $leftOut[] = $name;
    }
}
sort($leftOut);

echo "\nLeft out of the port: " . ($leftOut === [] ? 'none' : implode(', ', $leftOut)) . "\n";

echo "\n{$derived} derived file(s), {$total} hunk(s) in total.\n";

exit($failed ? 1 : 0);
