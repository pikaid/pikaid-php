<?php

declare(strict_types=1);

/**
 * Test-only JSON-Lines adapter for the shared PikaID conformance suite.
 *
 * This file is NOT part of the distributable package (the composer archive
 * excludes /tests/). It exists solely so that tools/run_adapter.py from the
 * immutable pikaid-specs checkout can drive the real Pikaid\Pikaid API.
 *
 * Rules enforced here:
 * - Every operation delegates to Pikaid\Pikaid or Pikaid\PikaidException.
 * - No identifier, binary value, validation decision or randomness is
 *   synthesized or normalized by this bridge.
 * - One JSON request line in, exactly one JSON response line out.
 * - Diagnostics go to stderr only.
 * - PikaidException (documented validation failures) maps to invalid_input;
 *   an injected CSPRNG failure maps to entropy_unavailable; anything else maps
 *   to internal_error. Failures are never laundered into invalid_input.
 *
 * CSPRNG fault injection deliberately runs the real generation path in a
 * child process started with disable_functions=random_bytes. That is the only
 * way to simulate an unavailable OS CSPRNG without adding a production RNG
 * seam or a weak fallback.
 */

require_once __DIR__ . '/../bootstrap.php';

use Pikaid\Pikaid;
use Pikaid\PikaidException;

final class AdapterInputRejected extends \RuntimeException
{
}

final class AdapterEntropyUnavailable extends \RuntimeException
{
}

final class AdapterUnsupportedOperation extends \RuntimeException
{
}

/**
 * Run the real generation path with random_bytes() disabled, then report the
 * observed outcome on stdout. Used only as a child process by JsonlAdapter.
 *
 * @param list<string> $argv
 */
function adapter_fault_probe(array $argv): int
{
    $operation = $argv[2] ?? '';
    $timestamp = null;
    if (isset($argv[3])) {
        $timestamp = (int) $argv[3];
    }

    try {
        $value = PikaidJsonlAdapter::generateValue($operation, $timestamp);
        \fwrite(\STDOUT, 'GENERATED ' . $value . "\n");

        return 0;
    } catch (\Throwable $exception) {
        $message = $exception->getMessage();
        $looksLikeMissingCsprng = \stripos($message, 'undefined function') !== false
            && \stripos($message, 'random_bytes') !== false;

        if ($looksLikeMissingCsprng) {
            \fwrite(\STDOUT, 'ENTROPY_UNAVAILABLE ' . $message . "\n");

            return 0;
        }

        \fwrite(\STDOUT, 'INTERNAL_ERROR ' . \get_class($exception) . ': ' . $message . "\n");

        return 0;
    }
}

final class PikaidJsonlAdapter
{
    private const PROTOCOL_VERSION = 1;

    private const BASE_CAPABILITIES = [
        'generate_string',
        'text_to_binary',
        'binary_to_text',
        'boolean_validation',
        'format_only_validation',
        'parse_components',
        'parse_datetime',
        'generate_with_timestamp',
        'generate_binary',
        'generate_binary_with_timestamp',
        'public_compare',
    ];

    private const GENERATION_OPERATIONS = [
        'generate_string',
        'generate_with_timestamp',
        'generate_binary',
        'generate_binary_with_timestamp',
    ];

    /** @var bool */
    private $faultActive = false;

    /** @var list<string> */
    private $capabilities;

    /** @var string|null */
    private $expectedBackend;

    public function __construct()
    {
        $this->capabilities = self::BASE_CAPABILITIES;
        if (\function_exists('proc_open') && \function_exists('stream_get_contents')) {
            $this->capabilities[] = 'csprng_failure_injection';
        }

        $this->expectedBackend = \getenv('PIKAID_EXPECT_BACKEND');
        if ($this->expectedBackend === false) {
            $this->expectedBackend = null;
        }

        $actual = self::backendName();
        \fwrite(\STDERR, \sprintf(
            "pikaid-official-adapter: backend=%s php=%s sut=%s\n",
            $actual,
            \PHP_VERSION,
            __DIR__ . '/../../src/Pikaid.php'
        ));

        if ($this->expectedBackend !== null && $this->expectedBackend !== $actual) {
            throw new \RuntimeException(\sprintf(
                'backend selection mismatch: expected "%s", actually used "%s"',
                $this->expectedBackend,
                $actual
            ));
        }
    }

    /**
     * Mirrors src/Pikaid.php::detectExtensions() precedence so the probe can
     * prove which arithmetic path a child process really exercises.
     */
    public static function backendName(): string
    {
        if (\function_exists('gmp_import')) {
            return 'gmp';
        }
        if (\function_exists('bcadd')) {
            return 'bcmath';
        }

        return 'pure';
    }

    /**
     * @param mixed $request
     * @return array<string, mixed>
     */
    public function handle($request): array
    {
        if (!\is_array($request)) {
            throw new \RuntimeException('request must be a JSON object');
        }

        $requestId = $request['request_id'] ?? null;
        if (!\is_int($requestId) || $requestId < 1) {
            throw new \RuntimeException('request_id must be a positive integer');
        }

        $operation = $request['operation'] ?? null;
        if (!\is_string($operation) || $operation === '') {
            throw new \RuntimeException('operation must be a non-empty string');
        }

        $arguments = $request['arguments'] ?? null;
        if (!\is_array($arguments)) {
            throw new \RuntimeException('arguments must be a JSON object');
        }

        try {
            $value = $this->dispatch($operation, $arguments);

            return ['request_id' => $requestId, 'ok' => true, 'value' => $value];
        } catch (AdapterInputRejected $exception) {
            return self::error($requestId, 'invalid_input', $exception->getMessage());
        } catch (AdapterEntropyUnavailable $exception) {
            return self::error($requestId, 'entropy_unavailable', $exception->getMessage());
        } catch (AdapterUnsupportedOperation $exception) {
            return self::error($requestId, 'unsupported_operation', $exception->getMessage());
        } catch (PikaidException $exception) {
            return self::error($requestId, 'invalid_input', $exception->getMessage());
        } catch (\Throwable $exception) {
            return self::error(
                $requestId,
                'internal_error',
                \get_class($exception) . ': ' . $exception->getMessage()
            );
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @return mixed
     */
    private function dispatch(string $operation, array $arguments)
    {
        if ($operation === 'hello') {
            return [
                'protocol_version' => self::PROTOCOL_VERSION,
                'capabilities' => $this->capabilities,
            ];
        }

        if ($operation === 'inject_fault') {
            return $this->injectFault($arguments);
        }

        if (\in_array($operation, self::GENERATION_OPERATIONS, true)) {
            if ($this->faultActive) {
                return $this->runFaultProbe($operation, $arguments);
            }

            return $this->generate($operation, $arguments);
        }

        switch ($operation) {
            case 'text_to_binary':
                return \bin2hex(Pikaid::toBinary(self::stringArgument($arguments, 'text')));

            case 'binary_to_text':
                return Pikaid::fromBinary(self::hexArgument($arguments, 'binary_hex'));

            case 'boolean_validation':
                return Pikaid::isValid(self::stringArgument($arguments, 'text'));

            case 'format_only_validation':
                return Pikaid::isWellFormed(self::stringArgument($arguments, 'text'));

            case 'parse_components':
                return self::parseComponents(self::stringArgument($arguments, 'text'));

            case 'public_compare':
                return Pikaid::compare(
                    self::stringArgument($arguments, 'a'),
                    self::stringArgument($arguments, 'b')
                );
        }

        throw new AdapterUnsupportedOperation('operation is not mapped by this bridge: ' . $operation);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return mixed
     */
    private function generate(string $operation, array $arguments)
    {
        $timestamp = null;
        if ($operation === 'generate_with_timestamp' || $operation === 'generate_binary_with_timestamp') {
            $timestamp = self::timestampArgument($arguments);
        }

        return self::generateValue($operation, $timestamp);
    }

    /**
     * @return mixed
     */
    public static function generateValue(string $operation, ?int $timestamp)
    {
        switch ($operation) {
            case 'generate_string':
                return Pikaid::generate();

            case 'generate_with_timestamp':
                if ($timestamp === null) {
                    throw new \InvalidArgumentException('timestamp is required');
                }

                return Pikaid::generateWithTimestamp($timestamp);

            case 'generate_binary':
                return \bin2hex(Pikaid::generateBinary());

            case 'generate_binary_with_timestamp':
                if ($timestamp === null) {
                    throw new \InvalidArgumentException('timestamp is required');
                }

                return \bin2hex(Pikaid::generateBinaryWithTimestamp($timestamp));
        }

        throw new \InvalidArgumentException('unknown generation operation: ' . $operation);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function injectFault(array $arguments): array
    {
        $fault = $arguments['fault'] ?? null;
        if ($fault !== 'csprng_failure') {
            throw new AdapterInputRejected('only the csprng_failure fault is supported');
        }

        $active = $arguments['active'] ?? null;
        if (!\is_bool($active)) {
            throw new AdapterInputRejected('active must be a boolean');
        }

        if (!\in_array('csprng_failure_injection', $this->capabilities, true)) {
            throw new AdapterUnsupportedOperation('csprng_failure_injection is not available in this runtime');
        }

        $this->faultActive = $active;

        return ['fault' => $fault, 'active' => $active];
    }

    /**
     * Execute the real generation path in a child process whose CSPRNG has
     * been removed. Any other failure is surfaced, never disguised.
     *
     * @param array<string, mixed> $arguments
     * @return mixed
     */
    private function runFaultProbe(string $operation, array $arguments)
    {
        if (!\function_exists('proc_open')) {
            throw new \RuntimeException('proc_open is unavailable; cannot inject a CSPRNG failure');
        }

        $timestamp = null;
        if ($operation === 'generate_with_timestamp' || $operation === 'generate_binary_with_timestamp') {
            $timestamp = self::timestampArgument($arguments);
        }

        $disabled = self::faultDisabledFunctions();
        $command = \escapeshellarg(\PHP_BINARY)
            . ' -d ' . \escapeshellarg('disable_functions=' . $disabled)
            . ' ' . \escapeshellarg(__FILE__)
            . ' --fault-probe ' . \escapeshellarg($operation);
        if ($timestamp !== null) {
            $command .= ' ' . \escapeshellarg((string) $timestamp);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = \proc_open($command, $descriptors, $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('cannot start the CSPRNG fault probe');
        }

        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);
        $status = \proc_close($process);

        $stdout = \trim((string) $stdout);
        $stderr = \trim((string) $stderr);

        if ($status === 0 && \strpos($stdout, 'ENTROPY_UNAVAILABLE') === 0) {
            $detail = \trim((string) \substr($stdout, \strlen('ENTROPY_UNAVAILABLE')));

            throw new AdapterEntropyUnavailable(
                'CSPRNG unavailable while generating: ' . ($detail !== '' ? $detail : 'random_bytes() is disabled')
            );
        }

        throw new \RuntimeException(\sprintf(
            'CSPRNG fault injection did not fail closed (exit %d, stdout "%s", stderr "%s")',
            $status,
            $stdout,
            $stderr
        ));
    }

    private static function faultDisabledFunctions(): string
    {
        $configured = (string) \ini_get('disable_functions');
        $disabled = [];
        foreach (\preg_split('/\s*,\s*/', $configured) ?: [] as $name) {
            $name = \trim($name);
            if ($name !== '') {
                $disabled[] = $name;
            }
        }

        if (!\in_array('random_bytes', $disabled, true)) {
            $disabled[] = 'random_bytes';
        }

        return \implode(',', $disabled);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private static function parseComponents(string $text): array
    {
        $parsed = Pikaid::parse($text);
        $datetime = $parsed['timestamp'];
        if (!$datetime instanceof \DateTimeImmutable) {
            throw new \RuntimeException('Pikaid::parse() did not return a DateTimeImmutable timestamp');
        }

        return [
            'timestamp_seconds' => $datetime->getTimestamp(),
            'randomness_hex' => $parsed['randomness'],
            'datetime_utc' => $datetime->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function stringArgument(array $arguments, string $name): string
    {
        $value = $arguments[$name] ?? null;
        if (!\is_string($value)) {
            throw new AdapterInputRejected($name . ' must be a string');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function timestampArgument(array $arguments): int
    {
        $value = $arguments['timestamp_seconds'] ?? null;
        if (!\is_int($value)) {
            throw new AdapterInputRejected('timestamp_seconds must be an integer');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function hexArgument(array $arguments, string $name): string
    {
        $hex = self::stringArgument($arguments, $name);
        if (\strlen($hex) % 2 !== 0 || \preg_match('/\A[0-9a-f]*\z/', $hex) !== 1) {
            throw new AdapterInputRejected($name . ' must be lowercase hexadecimal with an even length');
        }

        $bytes = \hex2bin($hex);
        if ($bytes === false) {
            throw new AdapterInputRejected($name . ' is not valid hexadecimal');
        }

        return $bytes;
    }

    /**
     * @return array<string, mixed>
     */
    private static function error(int $requestId, string $category, string $message): array
    {
        if ($message === '') {
            $message = $category;
        }

        return [
            'request_id' => $requestId,
            'ok' => false,
            'error' => ['category' => $category, 'message' => $message],
        ];
    }
}

function adapter_main(): int
{
    try {
        $adapter = new PikaidJsonlAdapter();
    } catch (\Throwable $exception) {
        \fwrite(\STDERR, 'pikaid-official-adapter: startup failed: ' . $exception->getMessage() . "\n");

        return 2;
    }

    while (($line = \fgets(\STDIN)) !== false) {
        if (\trim($line) === '') {
            continue;
        }

        try {
            $request = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            \fwrite(\STDERR, 'pikaid-official-adapter: malformed JSON request: ' . $exception->getMessage() . "\n");

            return 2;
        }

        try {
            $response = $adapter->handle($request);
        } catch (\Throwable $exception) {
            \fwrite(\STDERR, 'pikaid-official-adapter: protocol failure: ' . $exception->getMessage() . "\n");

            return 2;
        }

        $encoded = \json_encode($response, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            \fwrite(\STDERR, 'pikaid-official-adapter: cannot encode response: ' . \json_last_error_msg() . "\n");

            return 2;
        }

        \fwrite(\STDOUT, $encoded . "\n");
        \fflush(\STDOUT);
    }

    return 0;
}

if (($argv[1] ?? '') === '--fault-probe') {
    exit(adapter_fault_probe($argv));
}

exit(adapter_main());
