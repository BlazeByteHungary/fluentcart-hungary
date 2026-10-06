<?php
declare(strict_types=1);

/**
 * Minimal standalone unit-test harness (no WordPress, no PHPUnit). Loads only the pure core classes.
 */

$GLOBALS['__fch_tests'] = ['pass' => 0, 'fail' => 0, 'results' => []];

$fchPluginDir = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($fchPluginDir): void {
    $prefixes = [
        'FluentCartHungary\\Tests\\Mock\\' => $fchPluginDir . '/tests/mock/',
        'FluentCartHungary\\' => $fchPluginDir . '/src/',
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $path = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
        return;
    }
});

function fch_test(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['__fch_tests']['pass']++;
        $GLOBALS['__fch_tests']['results'][] = ['ok' => true, 'name' => $name, 'error' => ''];
    } catch (\Throwable $e) {
        $GLOBALS['__fch_tests']['fail']++;
        $GLOBALS['__fch_tests']['results'][] = ['ok' => false, 'name' => $name, 'error' => $e->getMessage()];
    }
}

function assert_true(bool $condition, string $message = 'expected true'): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf(
            '%s expected %s, got %s',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assert_throws(callable $fn, string $exceptionClass, string $message = ''): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($e instanceof $exceptionClass) {
            return;
        }
        throw new \RuntimeException(sprintf('%s threw %s: %s', $message, get_class($e), $e->getMessage()));
    }
    throw new \RuntimeException($message . ' did not throw ' . $exceptionClass);
}

function fch_summary(): int
{
    $t = $GLOBALS['__fch_tests'];
    foreach ($t['results'] as $result) {
        printf("[%s] %s%s\n", $result['ok'] ? 'PASS' : 'FAIL', $result['name'], $result['ok'] ? '' : ' :: ' . $result['error']);
    }
    printf("\nUNIT SUMMARY: %d passed, %d failed, %d total\n", $t['pass'], $t['fail'], $t['pass'] + $t['fail']);
    return $t['fail'] === 0 ? 0 : 1;
}
