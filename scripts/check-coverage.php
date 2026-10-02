<?php

declare(strict_types=1);

$reportPath = $argv[1] ?? '';
$minimum = isset($argv[2]) ? (float) $argv[2] : 80.0;

if ($reportPath === '' || ! is_file($reportPath)) {
    fwrite(STDERR, "Coverage report not found: {$reportPath}\n");
    exit(2);
}

$report = simplexml_load_file($reportPath);

if ($report === false) {
    fwrite(STDERR, "Coverage report is not valid XML.\n");
    exit(2);
}

$metrics = $report->project->metrics;
$statements = (int) $metrics['statements'];
$coveredStatements = (int) $metrics['coveredstatements'];
$coverage = $statements > 0 ? ($coveredStatements / $statements) * 100 : 0.0;

printf("Line coverage: %.2f%% (required: %.2f%%)\n", $coverage, $minimum);

if ($coverage + PHP_FLOAT_EPSILON < $minimum) {
    fwrite(STDERR, "Coverage is below the required floor.\n");
    exit(1);
}
