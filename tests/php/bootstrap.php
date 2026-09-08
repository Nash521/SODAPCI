<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$GLOBALS['form_validator_tests'] = [];
$GLOBALS['form_validator_failures'] = [];

function test(string $name, callable $callback): void
{
    $GLOBALS['form_validator_tests'][] = [$name, $callback];
}

function assertTrue(bool $condition, string $message = 'Assertion failed.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message ?: sprintf(
            "Expected %s, got %s.",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    assertTrue(str_contains($haystack, $needle), $message ?: "Expected '$needle' in '$haystack'.");
}

function assertThrows(string $exceptionClass, string $message, callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        assertSame($exceptionClass, $exception::class);
        assertSame($message, $exception->getMessage());
        return;
    }

    throw new RuntimeException("Expected $exceptionClass to be thrown.");
}
