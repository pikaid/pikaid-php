<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Proves that all three arithmetic backends are genuinely exercised and that
 * they produce identical results on the same pinned matrix.
 *
 * src/Pikaid.php selects its backend from function_exists(). Running the same
 * probe in child processes with selected extension functions disabled is the
 * only way to exercise all three paths on a machine where GMP and BCMath are
 * both compiled in. The probe reports the backend it actually used, so a
 * misconfigured child cannot pass as the wrong path.
 */
final class BackendParityTest extends TestCase
{
    private const GMP_FUNCTIONS = ['gmp_import', 'gmp_export', 'gmp_init', 'gmp_strval'];
    private const BCMATH_FUNCTIONS = ['bcadd', 'bcmul', 'bccomp', 'bcmod', 'bcdiv'];

    public function testEveryAvailableBackendProducesIdenticalResults(): void
    {
        $configurations = [];

        if (extension_loaded('gmp')) {
            $configurations['gmp'] = [
                'expected' => ['gmp' => true, 'bcmath' => false],
                'disabled' => self::BCMATH_FUNCTIONS,
            ];
        }

        if (extension_loaded('bcmath')) {
            $configurations['bcmath'] = [
                'expected' => ['gmp' => false, 'bcmath' => true],
                'disabled' => self::GMP_FUNCTIONS,
            ];
        }

        $configurations['pure-php'] = [
            'expected' => ['gmp' => false, 'bcmath' => false],
            'disabled' => array_merge(self::GMP_FUNCTIONS, self::BCMATH_FUNCTIONS),
        ];

        // The pure fallback must be testable everywhere, so this is a hard
        // requirement rather than a conditional skip.
        self::assertArrayHasKey('pure-php', $configurations);

        $checkCounts = [];

        foreach ($configurations as $name => $configuration) {
            $result = $this->runProbe($configuration['disabled']);

            self::assertSame(0, $result['exit'], $name . ' probe failed: ' . $result['stderr'] . $result['stdout']);

            $report = json_decode(trim($result['stdout']), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($report, $name . ' probe output');

            self::assertSame($configuration['expected']['gmp'], $report['gmp'], $name . ': GMP path selection');
            self::assertSame($configuration['expected']['bcmath'], $report['bcmath'], $name . ': BCMath path selection');
            self::assertGreaterThan(300, $report['checks'], $name . ': checks executed');

            $checkCounts[$name] = $report['checks'];
        }

        // Same inputs, same number of executed checks on every backend.
        self::assertCount(1, array_unique($checkCounts), 'backends executed different numbers of checks: ' . json_encode($checkCounts));

        if (extension_loaded('gmp') && extension_loaded('bcmath')) {
            self::assertSame(['bcmath', 'gmp', 'pure-php'], $this->sortedKeys($checkCounts));
        }
    }

    /**
     * @param list<string> $disabledFunctions
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runProbe(array $disabledFunctions): array
    {
        $command = [
            PHP_BINARY,
            '-d',
            'disable_functions=' . implode(',', $disabledFunctions),
            __DIR__ . '/backends.php',
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__));
        self::assertIsResource($process, 'probe process');

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }

    /**
     * @param array<string, int> $values
     * @return list<string>
     */
    private function sortedKeys(array $values): array
    {
        $keys = array_keys($values);
        sort($keys);

        return $keys;
    }
}
