<?php

declare(strict_types=1);

$tests = [
    __DIR__ . '/../tests/Unit/SearchContractTest.php',
    __DIR__ . '/../tests/Unit/SearchFailsClosedTest.php',
];

foreach ($tests as $test) {
    if (!is_file($test)) {
        fwrite(STDERR, "Missing required test file: {$test}" . PHP_EOL);
        exit(1);
    }
    require $test;
}

echo "Larena Search contract skeleton tests passed.\n";
