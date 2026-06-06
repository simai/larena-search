<?php

declare(strict_types=1);

$tests = [
    __DIR__ . '/../tests/Unit/SearchContractTest.php',
    __DIR__ . '/../tests/Unit/SearchFailsClosedTest.php',
    __DIR__ . '/../tests/Unit/InMemorySearchRuntimeTest.php',
    __DIR__ . '/../tests/Unit/InMemorySearchRuntimeFailsClosedTest.php',
];

foreach ($tests as $test) {
    if (!is_file($test)) {
        fwrite(STDERR, "Missing required test file: {$test}" . PHP_EOL);
        exit(1);
    }
    require $test;
}

echo "Larena Search runtime baseline tests passed.\n";
