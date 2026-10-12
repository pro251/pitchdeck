<?php

/*
 * The test runner.
 *
 * Plain PHP, no framework and no composer, because the whole application is
 * plain PHP with no framework and no composer -- adding a test dependency
 * would make the test suite the heaviest thing in the project. Every test
 * here runs in milliseconds against local files.
 *
 * Usage: php tests/run.php [filter]
 */

$root = dirname(__DIR__);

foreach (['Rng', 'Http', 'Drift', 'Images', 'Copy', 'Deck', 'Pptx', 'Store', 'App'] as $class) {
    require $root.'/src/'.$class.'.php';
}

$GLOBALS['tests'] = [];
$GLOBALS['filter'] = $argv[1] ?? '';

function test(string $name, callable $body): void
{
    $GLOBALS['tests'][] = [$name, $body];
}

function expect(mixed $actual): Expectation
{
    return new Expectation($actual);
}

final class Expectation
{
    public function __construct(private mixed $actual) {}

    public function toBe(mixed $expected, string $why = ''): self
    {
        if ($this->actual !== $expected) {
            throw new AssertionError(sprintf(
                "%sexpected %s, got %s",
                $why ? "$why: " : '',
                var_export($expected, true),
                var_export($this->actual, true),
            ));
        }

        return $this;
    }

    public function toHaveCount(int $n, string $why = ''): self
    {
        if (count($this->actual) !== $n) {
            throw new AssertionError(($why ? "$why: " : '')."expected $n items, got ".count($this->actual));
        }

        return $this;
    }

    public function toContain(mixed $needle, string $why = ''): self
    {
        $found = is_string($this->actual)
            ? str_contains($this->actual, (string) $needle)
            : in_array($needle, $this->actual, true);

        if (! $found) {
            throw new AssertionError(($why ? "$why: " : '').var_export($needle, true).' is not in '.var_export($this->actual, true));
        }

        return $this;
    }

    public function notToContain(mixed $needle, string $why = ''): self
    {
        $found = is_string($this->actual)
            ? str_contains($this->actual, (string) $needle)
            : in_array($needle, $this->actual, true);

        if ($found) {
            throw new AssertionError(($why ? "$why: " : '').var_export($needle, true).' should not be present');
        }

        return $this;
    }

    public function toBeTruthy(string $why = ''): self
    {
        if (! $this->actual) {
            throw new AssertionError(($why ? "$why: " : '').'expected truthy, got '.var_export($this->actual, true));
        }

        return $this;
    }

    public function toBeFalsy(string $why = ''): self
    {
        if ($this->actual) {
            throw new AssertionError(($why ? "$why: " : '').'expected falsy, got '.var_export($this->actual, true));
        }

        return $this;
    }
}

/** Reach a private method, for the few pieces of logic worth testing directly. */
function callPrivate(object $object, string $method, array $args = []): mixed
{
    $m = new ReflectionMethod($object, $method);
    $m->setAccessible(true);

    return $m->invokeArgs($object, $args);
}

foreach (glob($root.'/tests/*Test.php') as $file) {
    require $file;
}

$passed = 0;
$failures = [];

foreach ($GLOBALS['tests'] as [$name, $body]) {
    if ($GLOBALS['filter'] !== '' && ! str_contains(strtolower($name), strtolower($GLOBALS['filter']))) {
        continue;
    }

    try {
        $body();
        $passed++;
        echo ".";
    } catch (Throwable $e) {
        $failures[] = [$name, $e->getMessage()];
        echo "F";
    }
}

echo "\n\n";

foreach ($failures as [$name, $message]) {
    echo "FAILED  $name\n        $message\n\n";
}

printf("%d passed, %d failed\n", $passed, count($failures));
exit($failures === [] ? 0 : 1);
