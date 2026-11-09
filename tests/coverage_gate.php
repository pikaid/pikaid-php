<?php

declare(strict_types=1);

/**
 * Enforces the measured coverage gate from a Clover report.
 *
 * Usage: php tests/coverage_gate.php <clover.xml> [min-lines] [min-methods] [min-branches]
 *
 * Thresholds are regression gates, not coverage targets: they are set just
 * below the measured value so that a real drop fails CI without turning every
 * harmless refactor into a build break. PCOV reports statement and method
 * coverage; when the report also contains conditional/branch counters (Xdebug
 * path coverage) the branch gate is enforced too.
 */

$file = $argv[1] ?? '';
$minLines = isset($argv[2]) ? (float) $argv[2] : 99.0;
$minMethods = isset($argv[3]) ? (float) $argv[3] : 95.0;
$minBranches = isset($argv[4]) ? (float) $argv[4] : 85.0;

if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "coverage_gate: Clover report not found: {$file}\n");
    exit(2);
}

$xml = simplexml_load_file($file);
if ($xml === false) {
    fwrite(STDERR, "coverage_gate: cannot parse {$file}\n");
    exit(2);
}

$project = null;
foreach ($xml->xpath('//metrics[@files]') ?: [] as $candidate) {
    $project = $candidate;
}

if ($project === null) {
    fwrite(STDERR, "coverage_gate: no project-level metrics found in {$file}\n");
    exit(2);
}

/**
 * @param SimpleXMLElement $metrics
 */
$percentage = static function ($covered, $total): ?float {
    $total = (int) $total;
    if ($total === 0) {
        return null;
    }

    return ((int) $covered) / $total * 100.0;
};

$lines = $percentage($project['coveredstatements'], $project['statements']);
$methods = $percentage($project['coveredmethods'], $project['methods']);
$conditionals = (int) $project['conditionals'];
$branches = $conditionals > 0 ? $percentage($project['coveredconditionals'], $conditionals) : null;

printf("Statements: %s (%d/%d)\n", $lines === null ? 'n/a' : sprintf('%.2f%%', $lines), (int) $project['coveredstatements'], (int) $project['statements']);
printf("Methods:    %s (%d/%d)\n", $methods === null ? 'n/a' : sprintf('%.2f%%', $methods), (int) $project['coveredmethods'], (int) $project['methods']);
printf("Branches:   %s\n", $branches === null ? 'not measured by this driver' : sprintf('%.2f%% (%d/%d)', $branches, (int) $project['coveredconditionals'], $conditionals));

$failures = [];
if ($lines === null || $lines < $minLines) {
    $failures[] = sprintf('statement coverage %s is below the %.2f%% gate', $lines === null ? 'n/a' : sprintf('%.2f%%', $lines), $minLines);
}
if ($methods === null || $methods < $minMethods) {
    $failures[] = sprintf('method coverage %s is below the %.2f%% gate', $methods === null ? 'n/a' : sprintf('%.2f%%', $methods), $minMethods);
}
if ($branches !== null && $branches < $minBranches) {
    $failures[] = sprintf('branch coverage %.2f%% is below the %.2f%% gate', $branches, $minBranches);
}

if ($failures !== []) {
    fwrite(STDERR, "COVERAGE GATE FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "COVERAGE GATE PASSED\n";
