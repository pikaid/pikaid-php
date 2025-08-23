<?php

declare(strict_types=1);

set_time_limit(600); // 10 minutes

/**
 * Reproducible micro-benchmark harness (no PHPBench).
 * - Global warm-up: 1 call per subject to pay autoload/JIT.
 * - Per-subject warm-up before each measurement.
 * - Multiple passes with randomized order.
 * - Uses hrtime(true) and reports medians (µs/op) + ratios vs Pikaid.
 */

require __DIR__ . '/../vendor/autoload.php';

use Pikaid\Pikaid;
use Ramsey\Uuid\Uuid;
use Ulid\Ulid;
use Hidehalo\Nanoid\Client as NanoidClient;

// ---------- Config ----------
const PASSES = 30; // number of independent samples per subject
const REVS   = 60000; // iterations per measurement (>= 20k recommended)
const WARMUP = 60000; // iterations for subject-specific warm-up
// ----------------------------

/** @return array<string, callable> */
function buildSubjects(): array
{
  static $subjects = null;
  if ($subjects !== null) {
    return $subjects;
  }
  $nanoid = new NanoidClient(); // reuse the client across calls

  $subjects = [
    'Pikaid' => fn() => Pikaid::generate(),
    'UUIDv1' => fn() => Uuid::uuid1()->toString(),
    'UUIDv4' => fn() => Uuid::uuid4()->toString(),
    'UUIDv6' => fn() => Uuid::uuid6()->toString(),
    'UUIDv7' => fn() => Uuid::uuid7()->toString(),
    'ULID'   => fn() => (string) Ulid::generate(),
    'NanoID' => fn() => $nanoid->generateId(),
  ];
  return $subjects;
}

function globalWarmup(): void
{
  foreach (buildSubjects() as $fn) {
    // One quick call per subject to trigger autoload/JIT
    $x = $fn();
    if ($x === '') {
      echo '';
    }
  }
}

function subjectWarmup(callable $fn, int $n = WARMUP): void
{
  for ($i = 0; $i < $n; $i++) {
    $x = $fn();
    if ($x === '') {
      echo '';
    }
  }
}

/** Measure µs/op for a given subject (single sample). */
function measure(callable $fn, int $n = REVS): float
{
  $t0 = hrtime(true);
  for ($i = 0; $i < $n; $i++) {
    $x = $fn();
    if ($x === '') {
      echo '';
    }
  }
  $t1 = hrtime(true);
  $ns_total = $t1 - $t0;
  return ($ns_total / $n) / 1000.0; // µs/op
}

/** @param list<float> $vals */
function median(array $vals): float
{
  sort($vals);
  $c = count($vals);
  if ($c === 0) return NAN;
  $mid = intdiv($c, 2);
  return ($c % 2) ? $vals[$mid] : (($vals[$mid - 1] + $vals[$mid]) / 2.0);
}

function run(): void
{
  $subjects = buildSubjects();

  // Global warm-up (autoload/JIT)
  globalWarmup();

  // Collect samples
  $samples = []; // name => list<float µs/op>
  for ($p = 0; $p < PASSES; $p++) {
    $order = array_keys($subjects);
    shuffle($order); // randomized order each pass
    foreach ($order as $name) {
      $fn = $subjects[$name];
      subjectWarmup($fn, WARMUP); // warm-up per subject
      $us = measure($fn, REVS); // one sample (µs/op)
      $samples[$name][] = $us;
    }
  }

  // Compute medians
  $medians = [];
  foreach ($samples as $name => $vals) {
    $medians[$name] = median($vals);
  }

  // Baseline = Pikaid
  $baseline = $medians['Pikaid'] ?? NAN;

  // Display
  $iters = REVS;
  $passes = PASSES;
  echo "Benchmarking {$passes} passes × {$iters} iterations (per subject)\n";
  echo "Warm-up per subject: " . WARMUP . " iterations\n\n";

  printf("%-10s %14s %10s\n", 'Library', 'median µs/op', 'ratio');
  echo str_repeat('-', 38) . "\n";
  foreach ($medians as $name => $us) {
    $ratio = (!is_nan($baseline) && $baseline > 0 && $name !== 'Pikaid')
      ? $us / $baseline
      : 1.0;
    printf("%-10s %14.3f %10.3f\n", $name, $us, $ratio);
  }
}

run();