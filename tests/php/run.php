<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $testFile) {
    require $testFile;
}

foreach ($GLOBALS['form_validator_tests'] as [$name, $callback]) {
    $GLOBALS['form_validator_temp_files'] = [];
    try {
        $callback();
        fwrite(STDOUT, "PASS $name\n");
    } catch (Throwable $exception) {
        $GLOBALS['form_validator_failures'][] = [$name, $exception];
        fwrite(STDERR, "FAIL $name: {$exception->getMessage()}\n");
    } finally {
        cleanupTemporaryFiles();
    }
}

$failed = count($GLOBALS['form_validator_failures']);
fwrite(STDOUT, sprintf("%d test(s), %d failure(s)\n", count($GLOBALS['form_validator_tests']), $failed));
exit($failed === 0 ? 0 : 1);
