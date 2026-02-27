<?php
// Quick profiling wrapper - runs Parser and measures each phase
// Must be run from the project root

require_once __DIR__ . '/../vendor/autoload.php';

$inputPath = __DIR__ . '/../data/data.csv';
$outputPath = __DIR__ . '/../data/data.json';

// We'll instrument by running the parse and timing from outside
// But we need internal timing. Let's just measure the total and estimate.

// Instead, let's time the parse directly
$t0 = hrtime(true);
new \App\Parser()->parse($inputPath, $outputPath);
$total = (hrtime(true) - $t0) / 1e6;
echo "Total: {$total}ms\n";
